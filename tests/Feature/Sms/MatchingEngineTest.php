<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Enums\MatchType;
use CartBecart\CardPay\Enums\ParseStatus;
use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\PaymentMatch;
use CartBecart\CardPay\Models\PaymentTokenReservation;
use CartBecart\CardPay\Services\Payments\PaymentStateMachine;
use CartBecart\CardPay\Services\Payments\TokenAllocator;
use CartBecart\CardPay\Services\Sms\MatchingEngine;
use CartBecart\CardPay\Tests\Support\RecordingWebhookEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A card is just an integer to the matcher (FKs are absent on SQLite), so these
 * fixtures skip the BankCard/Crypto graph entirely and keep the suite focused on
 * matching behaviour.
 */
const CARD = 7;

function pendingPaymentFor(int $cardId, int $amount, array $overrides = []): Payment
{
    return Payment::factory()->create([
        'application_id' => 1,
        'bank_card_id' => $cardId,
        'payable_amount' => $amount,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->addMinutes(30),
        ...$overrides,
    ]);
}

/**
 * A successfully-parsed credit SMS on a card, defaulting to "just now, in
 * window". Callers override received_at/amount/card to exercise the guards.
 */
function creditSms(int $cardId, int $amount, array $overrides = []): IncomingSms
{
    return IncomingSms::query()->create([
        'device_id' => 1,
        'bank_card_id' => $cardId,
        'message_id' => 'msg_'.Str::random(12),
        'raw_sms' => "deposit of {$amount} received",
        'received_at' => now(),
        'server_received_at' => now(),
        'parse_status' => ParseStatus::Parsed,
        'parsed_amount' => $amount,
        'match_status' => MatchStatus::Unmatched,
        ...$overrides,
    ]);
}

beforeEach(function () {
    // Freeze the clock so window comparisons and the cooldown deadline are exact.
    $this->freezeTime();

    $this->emitter = new RecordingWebhookEmitter;
    $this->engine = new MatchingEngine(
        new PaymentStateMachine,
        new TokenAllocator,
        $this->emitter,
    );
});

describe('exactly one candidate → auto-confirm (the happy path)', function () {
    it('pays the single matching payment, records evidence, links the SMS, and emits once', function () {
        $payment = pendingPaymentFor(CARD, 1_000_000);

        // A live reservation so we can prove the amount goes onto cooldown.
        $reservation = PaymentTokenReservation::query()->create([
            'payment_id' => $payment->id,
            'bank_card_id' => CARD,
            'payable_amount' => 1_000_000,
            'token' => 42,
            'active_key' => true,
            'release_at' => null,
        ]);

        $sms = creditSms(CARD, 1_000_000);

        $outcome = $this->engine->match($sms);

        // Outcome
        expect($outcome->isMatched())->toBeTrue()
            ->and($outcome->payment->id)->toBe($payment->id);

        // Payment moved to paid, linked to the SMS
        $freshPayment = $payment->fresh();
        expect($freshPayment->status)->toBe(PaymentStatus::Paid)
            ->and($freshPayment->paid_at)->not->toBeNull()
            ->and($freshPayment->matched_sms_id)->toBe($sms->id);

        // SMS marked matched and consumed
        $freshSms = $sms->fresh();
        expect($freshSms->match_status)->toBe(MatchStatus::Matched)
            ->and($freshSms->matched_payment_id)->toBe($payment->id)
            ->and($freshSms->used_at)->not->toBeNull();

        // Exactly one evidence row, automatic + exact
        $matches = PaymentMatch::query()->where('payment_id', $payment->id)->get();
        expect($matches)->toHaveCount(1);
        expect($matches->first()->match_type)->toBe(MatchType::Automatic)
            ->and($matches->first()->confidence)->toBe('exact')
            ->and($matches->first()->incoming_sms_id)->toBe($sms->id)
            ->and($matches->first()->decided_by)->toBeNull();

        // Amount put on cooldown (release scheduled, still active until released)
        $freshReservation = $reservation->fresh();
        expect($freshReservation->active_key)->toBeTrue()
            ->and($freshReservation->release_at)->not->toBeNull()
            ->and($freshReservation->release_at->toDateTimeString())
            ->toBe(now()->addMinutes(10)->toDateTimeString());

        // Exactly one webhook: payment.paid for this payment
        expect($this->emitter->emitted)->toHaveCount(1)
            ->and($this->emitter->emitted[0]['event'])->toBe(WebhookEventType::Paid)
            ->and($this->emitter->emitted[0]['payment_id'])->toBe($payment->id);
    });
});

describe('more than one candidate → ambiguous, none paid (AC-4)', function () {
    it('escalates every candidate to manual_review and pays none', function () {
        // Two payments share an amount+card (which the allocator prevents in
        // production, but the matcher must fail safe if it ever happens).
        $a = pendingPaymentFor(CARD, 2_000_000);
        $b = pendingPaymentFor(CARD, 2_000_000);

        $sms = creditSms(CARD, 2_000_000);

        $outcome = $this->engine->match($sms);

        expect($outcome->status)->toBe(MatchStatus::Ambiguous)
            ->and($outcome->manualReviewPayments)->toHaveCount(2);

        // NEITHER payment is paid; both are in manual review.
        foreach ([$a, $b] as $payment) {
            $fresh = $payment->fresh();
            expect($fresh->status)->toBe(PaymentStatus::ManualReview)
                ->and($fresh->paid_at)->toBeNull()
                ->and($fresh->matched_sms_id)->toBeNull();
        }

        // No payment anywhere ended up paid.
        expect(Payment::query()->where('status', PaymentStatus::Paid->value)->count())->toBe(0);

        // One review row per candidate, all pointing at this SMS.
        $reviews = ManualReviewRequest::query()->where('incoming_sms_id', $sms->id)->get();
        expect($reviews)->toHaveCount(2);
        foreach ($reviews as $review) {
            expect($review->reported_amount)->toBe(2_000_000)
                ->and($review->status)->toBe('pending');
        }
        expect($reviews->pluck('payment_id')->sort()->values()->all())
            ->toBe(collect([$a->id, $b->id])->sort()->values()->all());

        // SMS flagged ambiguous and consumed; no automatic evidence rows.
        $freshSms = $sms->fresh();
        expect($freshSms->match_status)->toBe(MatchStatus::Ambiguous)
            ->and($freshSms->used_at)->not->toBeNull();
        expect(PaymentMatch::query()->count())->toBe(0);

        // One manual_review webhook per escalated payment — never a paid event.
        expect($this->emitter->emitted)->toHaveCount(2);
        foreach ($this->emitter->emitted as $emission) {
            expect($emission['event'])->toBe(WebhookEventType::ManualReview);
        }
    });
});

describe('no candidate → unmatched', function () {
    it('marks the SMS unmatched and touches no payment when the amount matches nothing', function () {
        $payment = pendingPaymentFor(CARD, 3_000_000);

        $sms = creditSms(CARD, 9_999_999); // no payment at this amount

        $outcome = $this->engine->match($sms);

        expect($outcome->status)->toBe(MatchStatus::Unmatched);

        $freshSms = $sms->fresh();
        expect($freshSms->match_status)->toBe(MatchStatus::Unmatched)
            ->and($freshSms->matched_payment_id)->toBeNull()
            ->and($freshSms->used_at)->toBeNull();

        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
        expect($this->emitter->emitted)->toBeEmpty();
    });
});

describe('late deposits never auto-confirm (AC-5)', function () {
    it('leaves an SMS that arrives after the payment window closed unmatched', function () {
        $payment = pendingPaymentFor(CARD, 4_000_000); // expires now +30m

        // Deposit SMS lands one minute after expiry.
        $sms = creditSms(CARD, 4_000_000, ['received_at' => now()->addMinutes(31)]);

        $outcome = $this->engine->match($sms);

        expect($outcome->status)->toBe(MatchStatus::Unmatched)
            ->and($sms->fresh()->match_status)->toBe(MatchStatus::Unmatched)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and($this->emitter->emitted)->toBeEmpty();
    });

    it('never revives an already-expired payment (only admin may expired→paid)', function () {
        $payment = pendingPaymentFor(CARD, 5_000_000, [
            'status' => PaymentStatus::Expired,
            'expires_at' => now()->subMinutes(5),
        ]);

        $sms = creditSms(CARD, 5_000_000);

        $outcome = $this->engine->match($sms);

        expect($outcome->status)->toBe(MatchStatus::Unmatched)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Expired)
            ->and($this->emitter->emitted)->toBeEmpty();
    });
});

describe('card scoping', function () {
    it('does not match a payment on a different card (no cross-card leak)', function () {
        $payment = pendingPaymentFor(CARD, 6_000_000);

        $sms = creditSms(CARD + 1, 6_000_000); // same amount, different card

        $outcome = $this->engine->match($sms);

        expect($outcome->status)->toBe(MatchStatus::Unmatched)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and($this->emitter->emitted)->toBeEmpty();
    });
});

describe('defensive input handling', function () {
    it('treats a non-parsed SMS as unmatched without querying candidates', function () {
        pendingPaymentFor(CARD, 7_000_000);

        $sms = creditSms(CARD, 7_000_000, [
            'parse_status' => ParseStatus::Failed,
            'parsed_amount' => null,
        ]);

        $outcome = $this->engine->match($sms);

        expect($outcome->status)->toBe(MatchStatus::Unmatched)
            ->and($this->emitter->emitted)->toBeEmpty();
    });
});

describe('single candidate that loses the confirmation race → manual_review', function () {
    it('flags the SMS for review and mutates nothing itself when a concurrent writer wins', function () {
        $payment = pendingPaymentFor(CARD, 8_000_000);

        // Simulate a concurrent writer that confirms the payment in the instant
        // between our candidate SELECT (which still sees it pending) and our
        // conditional UPDATE. Hooking `retrieved` lets us interpose deterministically.
        $raced = false;
        Payment::retrieved(function (Payment $model) use ($payment, &$raced) {
            if (! $raced && $model->getKey() === $payment->id) {
                $raced = true;
                Payment::query()
                    ->whereKey($payment->id)
                    ->where('status', PaymentStatus::Pending->value)
                    ->update([
                        'status' => PaymentStatus::Paid->value,
                        'paid_at' => now(),
                    ]);
            }
        });

        $sms = creditSms(CARD, 8_000_000);

        $outcome = $this->engine->match($sms);

        // Our SMS path lost the race: it escalates, it does not pay.
        expect($outcome->status)->toBe(MatchStatus::ManualReview);

        $freshSms = $sms->fresh();
        expect($freshSms->match_status)->toBe(MatchStatus::ManualReview)
            ->and($freshSms->matched_payment_id)->toBeNull()
            ->and($freshSms->used_at)->not->toBeNull();

        // The payment is paid — but by the other writer, not by our SMS path:
        // no evidence row and no webhook were produced on this path.
        expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
        expect(PaymentMatch::query()->count())->toBe(0);
        expect($this->emitter->emitted)->toBeEmpty();
    });
});
