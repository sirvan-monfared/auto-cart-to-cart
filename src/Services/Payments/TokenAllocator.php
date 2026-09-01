<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Payments;

use CartBecart\CardPay\Exceptions\TokenPoolExhaustedException;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\PaymentTokenReservation;
use Illuminate\Database\QueryException;

/**
 * Race-safe unique-amount allocator (§A1).
 *
 * Each open payment on a given card must have a globally-distinct payable
 * amount so a deposit SMS maps to exactly one order. We achieve that WITHOUT a
 * read-then-write check (which races): we CSPRNG-shuffle the token space and
 * attempt to INSERT a reservation for each candidate, relying solely on the
 * `UNIQUE(bank_card_id, payable_amount, active_key)` index to reject collisions.
 * The first INSERT that survives is the winner; a unique violation just means
 * "amount busy, try the next token". This is the sole concurrency guard — under
 * parallel allocation the database, not the application, arbitrates.
 *
 * Releasing a reservation sets `active_key = NULL`, which (by SQL NULL-distinct
 * semantics in both MySQL and SQLite) drops the row out of the unique scope and
 * frees the amount while preserving the historical record.
 */
final class TokenAllocator
{
    /**
     * Reserve a unique payable amount for a card.
     *
     * @param  int  $digits  Token width per §A1: 2 → tokens 1..99, 3 → tokens 1..999.
     *
     * @throws TokenPoolExhaustedException when every slot for the card is taken.
     */
    public function allocate(int $bankCardId, int $originalAmount, int $digits): PaymentTokenReservation
    {
        foreach ($this->shuffledPool($digits) as $token) {
            $payable = $originalAmount + $token;

            try {
                return PaymentTokenReservation::query()->create([
                    'payment_id' => null,
                    'bank_card_id' => $bankCardId,
                    'payable_amount' => $payable,
                    'token' => $token,
                    'active_key' => true,
                    'release_at' => null,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    continue; // amount busy → next candidate
                }

                throw $e; // a real database error must not be swallowed
            }
        }

        throw new TokenPoolExhaustedException($bankCardId);
    }

    /**
     * Attach a freshly-created payment to its reservation (§A1 bind).
     */
    public function bind(PaymentTokenReservation $reservation, int $paymentId): void
    {
        $reservation->update(['payment_id' => $paymentId]);
    }

    /**
     * Bind the newest unbound reservation for a just-persisted payment's
     * card + payable amount. Used when the driver (not the service) performed
     * the allocation and the reservation object wasn't handed back.
     * The `payment_id IS NULL` predicate keeps it race-safe: only an unbound
     * row can be claimed, so two payments can never share one reservation.
     */
    public function bindReservation(Payment $payment): void
    {
        PaymentTokenReservation::query()
            ->where('bank_card_id', $payment->bank_card_id)
            ->where('payable_amount', $payment->payable_amount)
            ->whereNull('payment_id')
            ->orderByDesc('id')
            ->limit(1)
            ->update(['payment_id' => $payment->id]);
    }

    /**
     * Schedule the amount to be freed after $minutes (§A1 cooldown). Applied to
     * the currently-active reservation of a settled payment so its amount cannot
     * be reused until the cooldown lapses — preventing a stale deposit SMS from
     * matching a brand-new order at the same amount.
     */
    public function cooldown(int $paymentId, int $minutes): void
    {
        PaymentTokenReservation::query()
            ->where('payment_id', $paymentId)
            ->where('active_key', true)
            ->update(['release_at' => now()->addMinutes($minutes)]);
    }

    /**
     * Release up to $limit reservations whose cooldown has elapsed (§A1 releaseDue,
     * §A9 budgeted maintenance). Returns the number actually released.
     *
     * Implemented as select-then-guarded-update rather than `UPDATE ... LIMIT`
     * because SQLite lacks UPDATE-LIMIT; the `active_key = 1` predicate on the
     * update keeps it race-safe against a concurrent release.
     */
    public function releaseDue(int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $ids = PaymentTokenReservation::query()
            ->where('active_key', true)
            ->whereNotNull('release_at')
            ->where('release_at', '<=', now())
            ->orderBy('release_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return PaymentTokenReservation::query()
            ->whereIn('id', $ids)
            ->where('active_key', true)
            ->update(['active_key' => null]);
    }

    /**
     * The full token space for $digits, CSPRNG-shuffled (Fisher–Yates with
     * random_int) so allocation order is unpredictable and unbiased.
     *
     * @return non-empty-list<int>
     */
    private function shuffledPool(int $digits): array
    {
        // digits ≥ 1 always yields at least token 1, so the pool is never empty.
        if ($digits < 1) {
            throw new \InvalidArgumentException('Token width must be at least 1.');
        }

        $upper = (10 ** $digits) - 1; // 1 → 9, 2 → 99, 3 → 999
        $source = range(1, $upper);

        /** @var non-empty-list<int> $shuffled — rebuilt so the list type survives analysis */
        $shuffled = [];

        for ($i = count($source) - 1; $i >= 0; $i--) {
            // Fisher–Yates over the tail, appending survivors: order is
            // uniformly shuffled and the appended keys stay 0-based sequential.
            $j = random_int(0, $i);
            $shuffled[] = $source[$j];
            $source[$j] = $source[$i];
        }

        return $shuffled;
    }

    /**
     * True when the query failed on an integrity/unique constraint (SQLSTATE
     * class 23000 — reported identically by MySQL 1062 and SQLite 19 via PDO).
     * On this INSERT the only reachable constraint is the amount-unique index.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}
