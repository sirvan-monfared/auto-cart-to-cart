<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Sms;

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Enums\MatchType;
use CartBecart\CardPay\Enums\ParseStatus;
use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\PaymentMatch;
use CartBecart\CardPay\Services\Payments\PaymentStateMachine;
use CartBecart\CardPay\Services\Payments\TokenAllocator;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The fail-safe matching engine (§FR-11 / §A4) — the heart of payment
 * recognition, and the component that MUST never guess with money.
 *
 * Given a parsed credit SMS it finds the pending payments on the same card
 * whose payable amount equals the parsed amount within the SMS's time window,
 * then applies the only three outcomes the spec permits:
 *
 *   • 0 candidates → the SMS is `unmatched` (a late or foreign deposit); nothing
 *     is confirmed. A deposit arriving after expiry can therefore never
 *     auto-confirm anything.
 *   • exactly 1    → an atomic conditional transition pending→paid. Winning the
 *     race links the SMS, records evidence, and starts the amount cooldown.
 *     LOSING the race (a concurrent writer already moved the row) is NOT a
 *     silent success — the SMS is flagged `manual_review`, and no payment moves.
 *   • more than 1  → *ambiguous*: every still-pending candidate is escalated to
 *     `manual_review` with a review row; NONE is paid.
 *
 * Uniqueness of the payable amount per card (guaranteed upstream by the token
 * allocator) means the ">1" case should be rare, but the engine treats it as a
 * first-class fail-safe rather than an impossibility.
 *
 * Financial mutations run inside a DB transaction; webhook emission happens only
 * AFTER commit, so an event is never emitted for a change that rolled back.
 */
final class MatchingEngine
{
    public function __construct(
        private readonly PaymentStateMachine $stateMachine,
        private readonly TokenAllocator $allocator,
        private readonly WebhookEmitter $emitter,
    ) {}

    /**
     * Resolve a parsed SMS against pending payments and persist the outcome onto
     * the SMS row. Safe to call only for a successfully parsed credit; any other
     * SMS is treated as unmatched (defensive — the ingestion layer gates this).
     */
    public function match(IncomingSms $sms): MatchOutcome
    {
        $amount = $sms->parsed_amount;

        if ($sms->parse_status !== ParseStatus::Parsed || $amount === null || $amount <= 0) {
            return $this->markUnmatched($sms);
        }

        $candidates = $this->candidates($sms->bank_card_id, $amount, $sms->received_at);

        return match (true) {
            $candidates->isEmpty() => $this->markUnmatched($sms),
            $candidates->count() > 1 => $this->resolveAmbiguous($sms, $candidates),
            default => $this->confirmSingle($sms, $candidates->first()),
        };
    }

    /**
     * The candidate set (§FR-11 query): same card, exact payable amount, still
     * pending, and the deposit instant falls within the payment's live window.
     * Ordered by id for a deterministic "first" in the single-candidate path.
     *
     * @return Collection<int, Payment>
     */
    private function candidates(int $bankCardId, int $amount, \DateTimeInterface $receivedAt): Collection
    {
        return Payment::query()
            ->where('bank_card_id', $bankCardId)
            ->where('payable_amount', $amount)
            ->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<=', $receivedAt)
            ->where('expires_at', '>=', $receivedAt)
            ->orderBy('id')
            ->get();
    }

    /**
     * Exactly one candidate: attempt the atomic confirmation. The whole
     * side-effect bundle (transition, SMS link, evidence, cooldown) is one
     * transaction; emission follows commit.
     */
    private function confirmSingle(IncomingSms $sms, Payment $payment): MatchOutcome
    {
        $paidAt = now();
        $won = false;

        DB::transaction(function () use ($sms, $payment, $paidAt, &$won) {
            $won = $this->stateMachine->transition($payment, PaymentStatus::Paid, [
                'paid_at' => $paidAt,
                'matched_sms_id' => $sms->id,
            ]);

            if (! $won) {
                return; // concurrent writer already moved it → fail-safe below
            }

            $sms->update([
                'match_status' => MatchStatus::Matched,
                'matched_payment_id' => $payment->id,
                'used_at' => $paidAt,
            ]);

            PaymentMatch::query()->create([
                'payment_id' => $payment->id,
                'incoming_sms_id' => $sms->id,
                'match_type' => MatchType::Automatic,
                'confidence' => 'exact',
                'decided_by' => null,
            ]);

            $this->allocator->cooldown($payment->id, $this->cooldownMinutes());
        });

        if (! $won) {
            // A single candidate we could not cleanly confirm is escalated, not
            // guessed at: flag the SMS for a human, touch no payment.
            $sms->update([
                'match_status' => MatchStatus::ManualReview,
                'used_at' => $paidAt,
            ]);

            return MatchOutcome::manualReview();
        }

        $this->emitter->emit($payment, WebhookEventType::Paid);

        return MatchOutcome::matched($payment);
    }

    /**
     * More than one candidate: escalate every candidate that is still pending to
     * manual review (a concurrently-moved one is left as-is), record a review row
     * per escalation, and mark the SMS ambiguous. No payment is auto-confirmed.
     *
     * @param  Collection<int, Payment>  $candidates
     */
    private function resolveAmbiguous(IncomingSms $sms, Collection $candidates): MatchOutcome
    {
        /** @var list<Payment> $escalated */
        $escalated = [];

        DB::transaction(function () use ($sms, $candidates, &$escalated) {
            foreach ($candidates as $payment) {
                $moved = $this->stateMachine->transition($payment, PaymentStatus::ManualReview);

                if (! $moved) {
                    continue; // already left pending (e.g. paid elsewhere) — leave it
                }

                ManualReviewRequest::query()->create([
                    'payment_id' => $payment->id,
                    'incoming_sms_id' => $sms->id,
                    'reported_amount' => $sms->parsed_amount,
                    'status' => 'pending',
                ]);

                $escalated[] = $payment;
            }

            $sms->update([
                'match_status' => MatchStatus::Ambiguous,
                'used_at' => now(),
            ]);
        });

        foreach ($escalated as $payment) {
            $this->emitter->emit($payment, WebhookEventType::ManualReview);
        }

        return MatchOutcome::ambiguous($escalated);
    }

    private function markUnmatched(IncomingSms $sms): MatchOutcome
    {
        $sms->update(['match_status' => MatchStatus::Unmatched]);

        return MatchOutcome::unmatched();
    }

    private function cooldownMinutes(): int
    {
        return (int) config('cardpay.cooldown_minutes', 10);
    }
}
