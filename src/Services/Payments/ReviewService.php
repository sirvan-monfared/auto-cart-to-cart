<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Payments;

use CartBecart\CardPay\Contracts\GatewayUser;
use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\PaymentMatch;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin decisions on the manual-review queue (§FR-12) — the human fallback of
 * the recognition path, held to the same fail-safe standard:
 *
 *   • only a PENDING review is decidable (else `review_not_found`);
 *   • approve: an optional SMS link must belong to the payment's OWN card
 *     (`invalid_sms` otherwise — evidence from another card can never confirm);
 *   • the payment moves via the CONDITIONAL state-machine transition, so among
 *     parallel decisions exactly one writer wins; losing the race is a safe
 *     no-op, never a second confirmation;
 *   • cooldown on the winning amount; `payment.paid` / `payment.rejected`
 *     emitted AFTER commit so a rollback never produces an event.
 */
final class ReviewService
{
    public function __construct(
        private readonly PaymentStateMachine $stateMachine,
        private readonly TokenAllocator $allocator,
        private readonly WebhookEmitter $emitter,
    ) {}

    /**
     * @throws ApiException review_not_found / invalid_sms
     */
    public function approve(int $reviewId, GatewayUser $admin, ?int $smsId = null, ?string $note = null): ManualReviewRequest
    {
        $review = ManualReviewRequest::query()->where('id', $reviewId)->where('status', 'pending')->first();

        if (! $review instanceof ManualReviewRequest) {
            throw ApiException::reviewNotFound();
        }

        $payment = $review->payment;

        if (! $payment instanceof Payment
            || ! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::ManualReview], true)) {
            // The payment already settled/terminated elsewhere — the review row
            // itself stays for the audit trail but cannot decide money.
            throw ApiException::paymentNotReviewable();
        }

        $sms = null;
        if ($smsId !== null) {
            $sms = IncomingSms::query()
                ->where('id', $smsId)
                ->where('bank_card_id', $payment->bank_card_id)
                ->first();

            if ($sms === null) {
                throw ApiException::invalidSms();
            }
        }

        try {
            $savedReview = DB::transaction(function () use ($review, $payment, $admin, $sms, $note): ?ManualReviewRequest {
                $won = $this->stateMachine->transition($payment, PaymentStatus::Paid, [
                    'paid_at' => now(),
                    'matched_sms_id' => $sms?->id,
                ]);

                if (! $won) {
                    return null; // concurrent decision won — fail-safe below
                }

                $review->forceFill([
                    'status' => 'approved',
                    'reviewed_by' => $admin->getKey(),
                    'reviewed_at' => now(),
                    'internal_note' => $note ?? $review->internal_note,
                    'incoming_sms_id' => $sms !== null ? $sms->id : $review->incoming_sms_id,
                    'actual_amount' => $sms !== null ? $sms->parsed_amount : $review->actual_amount,
                ])->save();

                if ($sms !== null) {
                    $sms->update([
                        'match_status' => MatchStatus::Matched,
                        'matched_payment_id' => $payment->id,
                        'used_at' => now(),
                    ]);

                    PaymentMatch::query()->create([
                        'payment_id' => $payment->id,
                        'incoming_sms_id' => $sms->id,
                        'match_type' => 'manual',
                        'confidence' => 'manual',
                        'decided_by' => $admin->getKey(),
                    ]);
                }

                $this->allocator->cooldown($payment->id, (int) config('cardpay.cooldown_minutes', 10));

                return $review;
            });
        } catch (Throwable) {
            throw ApiException::paymentNotReviewable();
        }

        if ($savedReview === null) {
            // Lost the race: another writer already decided this payment. That
            // is a conflict for THIS caller — 409, and no double confirmation.
            throw ApiException::paymentNotReviewable();
        }

        $this->emitter->emit($payment, WebhookEventType::Paid);

        return $savedReview;
    }

    /**
     * @throws ApiException review_not_found
     */
    public function reject(int $reviewId, GatewayUser $admin, ?string $note = null): ManualReviewRequest
    {
        $review = ManualReviewRequest::query()->where('id', $reviewId)->where('status', 'pending')->first();

        if (! $review instanceof ManualReviewRequest) {
            throw ApiException::reviewNotFound();
        }

        $payment = $review->payment;

        if (! $payment instanceof Payment) {
            // A review without its payment cannot decide anything.
            throw ApiException::reviewNotFound();
        }

        try {
            $decided = DB::transaction(function () use ($review, $payment, $admin, $note): ?ManualReviewRequest {
                $won = false;

                if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::ManualReview], true)) {
                    $won = $this->stateMachine->transition($payment, PaymentStatus::Rejected);
                }

                if (! $won) {
                    return null;
                }

                $review->forceFill([
                    'status' => 'rejected',
                    'reviewed_by' => $admin->getKey(),
                    'reviewed_at' => now(),
                    'internal_note' => $note ?? $review->internal_note,
                ])->save();

                $this->allocator->cooldown($payment->id, (int) config('cardpay.cooldown_minutes', 10));

                return $review;
            });
        } catch (Throwable) {
            throw ApiException::paymentNotReviewable();
        }

        if ($decided === null) {
            throw ApiException::paymentNotReviewable();
        }

        $this->emitter->emit($payment, WebhookEventType::Rejected);

        return $decided;
    }
}
