<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Payments;

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Exceptions\TokenPoolExhaustedException;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\IdempotencyKey;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Drivers\DriverRegistry;
use CartBecart\CardPay\Services\Drivers\PaymentDriver;
use CartBecart\CardPay\Services\Idempotency\IdempotencyStore;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the merchant payment lifecycle (§FR-7): idempotent creation,
 * ownership-scoped lookup / verify, and cancellation.
 *
 * Creation is the critical path — it must be race-safe, replay-safe, and free of
 * side effects on every failure path. It composes three concurrency guards, each
 * arbitrated by the database rather than the application:
 *
 *   - the idempotency ledger's UNIQUE(application_id, idempotency_key) — one
 *     winner per key; losers re-select and replay (§A8),
 *   - the token allocator's UNIQUE amount index — one payable amount per open
 *     payment per card (§A1), and
 *   - the state machine's conditional UPDATE — one winner per transition (§9.2).
 *
 * The whole create — claim key, allocate token, insert payment, bind reservation,
 * freeze the response — runs inside ONE transaction, so a partial create can
 * never persist. Only after commit is the `payment.created` event recorded, and
 * that is best-effort: a webhook-recording hiccup must never unwind a committed
 * payment. Due-webhook DELIVERY is driven post-response by the lazy-maintenance
 * layer (§A9), never inline, so the create call itself stays latency-free.
 */
final class PaymentService
{
    public function __construct(
        private readonly IdempotencyStore $idempotency,
        private readonly TokenAllocator $tokens,
        private readonly PaymentStateMachine $stateMachine,
        private readonly WebhookEmitter $webhooks,
        private readonly DriverRegistry $drivers,
    ) {}

    /** The payment method this deployment is running (§5/§16). */
    private function driver(): PaymentDriver
    {
        return $this->drivers->active();
    }

    /**
     * Create a pending payment, or replay a prior create that used the same
     * idempotency key (§FR-7).
     *
     * @param  array<string, mixed>  $body  The decoded request body.
     * @param  string  $idempotencyKey  The Idempotency-Key header value ('' if absent).
     */
    public function create(Application $app, array $body, string $idempotencyKey): PaymentResult
    {
        // Validate everything BEFORE touching the ledger, so a bad request can
        // never claim a key or allocate a token.
        $this->assertIdempotencyKey($idempotencyKey);
        $amount = $this->validatedAmount($body);
        $card = $this->resolveBankCard($app, $body);
        $allowed = $app->allowedDomainList();
        $this->assertCallbackUrl($body['return_url'] ?? null, 'return_url', $allowed);
        $this->assertCallbackUrl($body['callback_url'] ?? null, 'callback_url', $allowed);

        $requestHash = $this->idempotency->hashRequest($body);

        // Fast path: a prior key row already exists → replay or conflict, no txn.
        $existing = $this->idempotency->find($app->id, $idempotencyKey);
        if ($existing !== null) {
            return $this->replayOrConflict($existing, $requestHash);
        }

        $payment = null;

        try {
            $driver = $this->driver();

            $result = DB::transaction(function () use (
                $app,
                $body,
                $card,
                $amount,
                $idempotencyKey,
                $requestHash,
                $driver,
                &$payment
            ): PaymentResult {
                $keyRow = $this->idempotency->begin($app->id, $idempotencyKey, $requestHash);

                // The driver reserves whatever makes this payment uniquely
                // matchable (card transfer: the §A1 token allocation).
                $reserved = $driver->reserveAmount($card, $amount, $app);
                $payment = $this->persistPayment($app, $body, $card, $amount, $reserved, $driver);
                $this->tokens->bindReservation($payment);

                $data = $this->present($payment, replay: false);
                $this->idempotency->complete($keyRow, $payment->id, $data);

                return new PaymentResult($data, created: true);
            });
        } catch (TokenPoolExhaustedException) {
            throw ApiException::tokenPoolExhausted();
        } catch (QueryException $e) {
            // A concurrent request may have claimed this key first; our losing
            // insert then violates UNIQUE(application_id, idempotency_key). Re-select
            // the committed winner and replay/conflict against it. Any OTHER
            // integrity error leaves no winner to find and is re-thrown untouched.
            if ($this->isUniqueViolation($e)) {
                $winner = $this->idempotency->find($app->id, $idempotencyKey);
                if ($winner !== null) {
                    return $this->replayOrConflict($winner, $requestHash);
                }
            }

            throw $e;
        }

        // Committed. Record the creation event; this must never unwind the payment.
        if ($payment instanceof Payment) {
            $this->safeEmit($payment, WebhookEventType::Created);
        }

        return $result;
    }

    /**
     * Look up one of the application's own payments by public id (§FR-7). The
     * application_id filter enforces tenant ownership: a payment belonging to
     * another application is indistinguishable from a missing one
     * (`payment_not_found`), so there is no cross-tenant leak.
     */
    public function find(Application $app, string $publicId): Payment
    {
        $payment = Payment::query()
            ->where('application_id', $app->id)
            ->where('public_id', $publicId)
            ->first();

        if ($payment === null) {
            throw ApiException::paymentNotFound();
        }

        return $payment;
    }

    /**
     * Cancel one of the application's pending payments (§FR-7). Only pending →
     * canceled is legal; losing the conditional transition (a concurrent path
     * paid/expired it first) fails safe as `payment_cannot_be_canceled`.
     */
    public function cancel(Application $app, string $publicId): PaymentResult
    {
        $payment = $this->find($app, $publicId);

        // Pre-flight for the precise 409 code. The state machine would also reject
        // a non-pending source, but as an internal transition error.
        if ($payment->status !== PaymentStatus::Pending) {
            throw ApiException::paymentCannotBeCanceled();
        }

        $won = $this->stateMachine->transition($payment, PaymentStatus::Canceled, [
            'canceled_at' => now(),
        ]);

        if (! $won) {
            // A concurrent settlement won the race: do nothing more (fail safe).
            throw ApiException::paymentCannotBeCanceled();
        }

        // Let the method release its reserved identity (card transfer: the
        // cooldown that blocks a stale deposit SMS from rematching).
        $this->driver()->releaseAmount($payment);
        $this->safeEmit($payment, WebhookEventType::Canceled);

        return new PaymentResult($this->present($payment, replay: false), created: false);
    }

    /**
     * The §FR-7 presentment `data` for a payment. Timestamps are ISO-8601 UTC;
     * `payment_id` is the opaque public id (numeric ids are never exposed).
     *
     * @return array<string, mixed>
     */
    public function present(Payment $payment, bool $replay): array
    {
        return [
            'payment_id' => $payment->public_id,
            'status' => $payment->status->value,
            'original_amount' => $payment->original_amount,
            'token' => $payment->token,
            'payable_amount' => $payment->payable_amount,
            'currency' => $payment->currency,
            'payment_url' => $this->paymentUrl($payment),
            'expires_at' => $payment->expires_at->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'idempotent_replay' => $replay,
        ];
    }

    // --- Creation internals ---------------------------------------------------

    /**
     * Decide replay vs conflict for an existing key row (§A8): same body hash →
     * replay the frozen response (200); different hash → 409 idempotency_conflict.
     */
    private function replayOrConflict(IdempotencyKey $row, string $requestHash): PaymentResult
    {
        if (! $this->idempotency->matches($row, $requestHash)) {
            throw ApiException::idempotencyConflict();
        }

        $stored = $row->response_json;
        if (is_array($stored) && $stored !== []) {
            $stored['idempotent_replay'] = true;

            return new PaymentResult($stored, created: false);
        }

        // A completed key should always carry its frozen response. If the row is
        // present but the response is missing (an in-flight claim that never
        // completed, or corruption), rebuild from the linked payment rather than
        // fabricate a success — and refuse outright if neither is recoverable.
        $payment = $row->payment_id !== null
            ? Payment::query()->find($row->payment_id)
            : null;

        if ($payment === null) {
            throw new RuntimeException("Idempotency key {$row->id} has no recoverable response.");
        }

        return new PaymentResult($this->present($payment, replay: true), created: false);
    }

    /**
     * Insert the pending payment row (§FR-7). Amount and token come straight
     * from the driver's reservation, so the stored payable_amount is exactly
     * the reserved one; the driver name is stamped for provenance.
     *
     * @param  array{token: int, payable_amount: int}  $reserved
     * @param  array<string, mixed>  $body
     */
    private function persistPayment(
        Application $app,
        array $body,
        BankCard $card,
        int $amount,
        array $reserved,
        PaymentDriver $driver,
    ): Payment {
        $customer = is_array($body['customer'] ?? null) ? $body['customer'] : [];

        return Payment::query()->create([
            'public_id' => 'PAY'.(string) Str::ulid(),
            'application_id' => $app->id,
            'bank_card_id' => $card->id,
            'driver' => $driver->name(),
            'external_order_id' => $this->cleanString($body['external_order_id'] ?? null),
            'original_amount' => $amount,
            'token' => $reserved['token'],
            'payable_amount' => $reserved['payable_amount'],
            'currency' => (string) config('cardpay.currency', 'IRR'),
            'description' => $this->cleanString($body['description'] ?? null),
            'customer_name' => $this->cleanString($customer['name'] ?? null),
            'customer_mobile' => $this->cleanString($customer['mobile'] ?? null),
            'customer_reference' => $this->cleanString($customer['reference'] ?? null),
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes($app->payment_expiration_minutes),
            'return_url' => $this->cleanString($body['return_url'] ?? null),
            'callback_url' => $this->cleanString($body['callback_url'] ?? null),
            'metadata_json' => is_array($body['metadata'] ?? null) ? $body['metadata'] : null,
        ]);
    }

    // --- Validation -----------------------------------------------------------

    private function assertIdempotencyKey(string $key): void
    {
        $min = (int) config('cardpay.idempotency.key_min', 8);
        $max = (int) config('cardpay.idempotency.key_max', 190);
        $length = strlen($key);

        if ($length < $min || $length > $max) {
            throw ApiException::validation([
                'Idempotency-Key' => "Header is required and must be {$min}–{$max} characters.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function validatedAmount(array $body): int
    {
        $amount = $body['amount'] ?? null;

        // Minor units only: a positive integer. Floats and numeric strings are
        // rejected so no rounding or coercion can ever alter the payable amount.
        if (! is_int($amount) || $amount < 1) {
            throw ApiException::invalidAmount();
        }

        return $amount;
    }

    /**
     * Resolve the target bank card: the requested id, else the application
     * default. The card must exist and be active (`invalid_bank_card`).
     *
     * @param  array<string, mixed>  $body
     */
    private function resolveBankCard(Application $app, array $body): BankCard
    {
        $requested = $body['bank_card_id'] ?? null;

        if ($requested !== null && ! is_int($requested)) {
            throw ApiException::invalidBankCard();
        }

        $cardId = $requested ?? $app->default_bank_card_id;
        if ($cardId === null) {
            throw ApiException::invalidBankCard();
        }

        $card = BankCard::query()->find($cardId);
        if ($card === null || ! $card->is_active) {
            throw ApiException::invalidBankCard();
        }

        return $card;
    }

    /**
     * Validate an optional return/callback URL: when supplied it must be an
     * absolute http(s) URL whose host passes the application allow-list (exact
     * host or subdomain match; an empty allow-list permits any host) (§SR-12).
     *
     * @param  list<string>  $allowedHosts
     */
    private function assertCallbackUrl(mixed $url, string $field, array $allowedHosts): void
    {
        if ($url === null || $url === '') {
            return; // optional — absent is fine
        }

        if (! is_string($url)) {
            throw ApiException::validation([$field => 'Must be an absolute http(s) URL.']);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw ApiException::validation([$field => 'Must be an absolute http(s) URL.']);
        }

        if (! $this->hostAllowed($host, $allowedHosts)) {
            throw ApiException::validation([$field => 'Host is not in the application allow-list.']);
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function hostAllowed(string $host, array $allowed): bool
    {
        if ($allowed === []) {
            return true; // empty allow-list permits all
        }

        foreach ($allowed as $entry) {
            if ($host === $entry || str_ends_with($host, '.'.$entry)) {
                return true; // exact host or subdomain
            }
        }

        return false;
    }

    // --- Utilities ------------------------------------------------------------

    private function paymentUrl(Payment $payment): string
    {
        return rtrim((string) config('app.url'), '/').'/p/'.$payment->public_id;
    }

    /**
     * Trim a would-be string field to a non-empty value, or null. Non-strings
     * (numbers, arrays, bools sent in the wrong slot) collapse to null.
     */
    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }

    private function safeEmit(Payment $payment, WebhookEventType $event): void
    {
        try {
            $this->webhooks->emit($payment, $event);
        } catch (Throwable $e) {
            // Recording is best-effort; a committed payment must never unwind
            // because event persistence hiccuped. Surface for diagnosis only.
            report($e);
        }
    }
}
