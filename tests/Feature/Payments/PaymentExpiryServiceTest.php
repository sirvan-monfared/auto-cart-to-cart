<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\PaymentTokenReservation;
use CartBecart\CardPay\Services\Payments\PaymentExpiryService;
use CartBecart\CardPay\Services\Payments\PaymentStateMachine;
use CartBecart\CardPay\Services\Payments\TokenAllocator;
use CartBecart\CardPay\Tests\Support\RecordingWebhookEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A pending payment with a controllable expiry, decoupled from the BankCard graph.
 */
function expiryPending(int $cardId, array $overrides = []): Payment
{
    return Payment::factory()->create([
        'application_id' => 1,
        'bank_card_id' => $cardId,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->addMinutes(30),
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->freezeTime();
    $this->emitter = new RecordingWebhookEmitter;
    $this->service = new PaymentExpiryService(
        new PaymentStateMachine,
        new TokenAllocator,
        $this->emitter,
    );
});

it('expires an overdue pending payment, cools down its amount, and emits payment.expired', function () {
    $payment = expiryPending(1, [
        'payable_amount' => 1_000_050,
        'expires_at' => now()->subMinute(),
    ]);

    $reservation = PaymentTokenReservation::query()->create([
        'payment_id' => $payment->id,
        'bank_card_id' => 1,
        'payable_amount' => 1_000_050,
        'token' => 50,
        'active_key' => true,
        'release_at' => null,
    ]);

    $count = $this->service->expireDue(20);

    expect($count)->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Expired);

    // Amount held for the cooldown so a late deposit SMS can't hit a reused amount.
    $freshReservation = $reservation->fresh();
    expect($freshReservation->active_key)->toBeTrue()
        ->and($freshReservation->release_at->toDateTimeString())
        ->toBe(now()->addMinutes(10)->toDateTimeString());

    expect($this->emitter->events())->toBe([WebhookEventType::Expired])
        ->and($this->emitter->emitted[0]['payment_id'])->toBe($payment->id);
});

it('leaves a payment whose window is still open untouched', function () {
    $payment = expiryPending(1, ['expires_at' => now()->addMinutes(5)]);

    $count = $this->service->expireDue(20);

    expect($count)->toBe(0)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($this->emitter->emitted)->toBeEmpty();
});

it('never touches a non-pending payment even if its expiry has passed', function () {
    $paid = expiryPending(1, ['status' => PaymentStatus::Paid, 'expires_at' => now()->subHour(), 'paid_at' => now()->subHour()]);
    $canceled = expiryPending(1, ['status' => PaymentStatus::Canceled, 'expires_at' => now()->subHour()]);
    $already = expiryPending(1, ['status' => PaymentStatus::Expired, 'expires_at' => now()->subHour()]);
    $review = expiryPending(1, ['status' => PaymentStatus::ManualReview, 'expires_at' => now()->subHour()]);

    $count = $this->service->expireDue(20);

    expect($count)->toBe(0)
        ->and($paid->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($canceled->fresh()->status)->toBe(PaymentStatus::Canceled)
        ->and($already->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($review->fresh()->status)->toBe(PaymentStatus::ManualReview)
        ->and($this->emitter->emitted)->toBeEmpty();
});

it('respects the batch limit and expires the oldest overdue payments first', function () {
    $oldest = expiryPending(1, ['payable_amount' => 1, 'expires_at' => now()->subMinutes(30)]);
    $middle = expiryPending(1, ['payable_amount' => 2, 'expires_at' => now()->subMinutes(20)]);
    $newest = expiryPending(1, ['payable_amount' => 3, 'expires_at' => now()->subMinutes(10)]);

    $count = $this->service->expireDue(2);

    expect($count)->toBe(2)
        ->and($oldest->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($middle->fresh()->status)->toBe(PaymentStatus::Expired)
        // The newest overdue one is left for the next budgeted run.
        ->and($newest->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($this->emitter->emitted)->toHaveCount(2);
});

it('does nothing for a non-positive budget', function () {
    expiryPending(1, ['expires_at' => now()->subMinute()]);

    expect($this->service->expireDue(0))->toBe(0)
        ->and($this->emitter->emitted)->toBeEmpty();
});
