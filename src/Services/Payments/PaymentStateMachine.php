<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Payments;

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Exceptions\InvalidStatusTransitionException;
use CartBecart\CardPay\Models\Payment;
use DateTimeInterface;

/**
 * The single authority for payment status changes (§9.2).
 *
 * Every state change funnels through {@see transition()}, which:
 *   1. rejects transitions not in the authoritative map (throws), and
 *   2. persists via a CONDITIONAL update `WHERE id = ? AND status = :from`.
 *
 * The conditional update is the concurrency guarantee: among any number of
 * parallel writers attempting the same `from → to`, the database lets exactly
 * one affect a row. The winner gets `true`; everyone else gets `false` and must
 * take the fail-safe path (never a second confirmation). Financial side effects
 * (cooldown, webhooks, match rows) run only for the winner.
 *
 * `canceled`/`rejected` are terminal and can never reach `paid` — enforced by
 * their absence from the map. `expired → paid` exists but only admins invoke it.
 */
final class PaymentStateMachine
{
    /**
     * Authoritative transition map: from-state → allowed to-states (§9.2).
     *
     * @var array<string, list<PaymentStatus>>
     */
    private const MAP = [
        'pending' => [
            PaymentStatus::Paid,
            PaymentStatus::Expired,
            PaymentStatus::Canceled,
            PaymentStatus::ManualReview,
        ],
        'manual_review' => [
            PaymentStatus::Paid,
            PaymentStatus::Rejected,
        ],
        'expired' => [
            PaymentStatus::ManualReview,
            PaymentStatus::Paid,
        ],
        // paid, canceled, rejected are terminal: no outgoing transitions.
    ];

    /**
     * Whether `from → to` is a legal transition.
     */
    public function can(PaymentStatus $from, PaymentStatus $to): bool
    {
        return in_array($to, self::MAP[$from->value] ?? [], true);
    }

    /**
     * Assert a transition is legal, throwing otherwise. Use for pre-flight
     * checks in admin flows that want to fail before touching the row.
     *
     * @throws InvalidStatusTransitionException
     */
    public function assertCan(PaymentStatus $from, PaymentStatus $to): void
    {
        if (! $this->can($from, $to)) {
            throw new InvalidStatusTransitionException($from, $to);
        }
    }

    /**
     * Atomically move a payment from its current status to $to.
     *
     * @param  array<string, mixed>  $extra  Additional columns to set on the
     *                                       winning update (e.g. paid_at, matched_sms_id).
     * @return bool true if THIS caller won the race and the row moved; false if
     *              a concurrent writer already moved it (fail-safe: do nothing more).
     *
     * @throws InvalidStatusTransitionException when $from → $to is not in the map.
     */
    public function transition(Payment $payment, PaymentStatus $to, array $extra = []): bool
    {
        $from = $payment->status;

        $this->assertCan($from, $to);

        // Build the DB-ready update: normalise datetimes to the model's storage
        // format so query-builder binding is unambiguous across drivers.
        $dbUpdate = ['status' => $to->value];
        foreach ($extra as $column => $value) {
            $dbUpdate[$column] = $value instanceof DateTimeInterface
                ? $payment->fromDateTime($value)
                : $value;
        }

        $affected = Payment::query()
            ->whereKey($payment->getKey())
            ->where('status', $from->value)
            ->update($dbUpdate);

        if ($affected !== 1) {
            // Lost the race, or the row already left $from. Fail safe.
            return false;
        }

        // Reflect the win in memory using the caller's original typed values so
        // casts (enum, datetime) apply correctly.
        $payment->status = $to;
        foreach ($extra as $column => $value) {
            $payment->setAttribute($column, $value);
        }
        $payment->syncOriginal();

        return true;
    }
}
