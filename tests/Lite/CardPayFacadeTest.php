<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Facades\CardPay;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Provisioning\GatewayProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| §16 — in-process payment creation
|--------------------------------------------------------------------------
|
| The point of lite: a single-site owner should not sign HMAC requests against
| their own application. The facade resolves the one gateway implicitly, so
| host code never mentions application_id, and the payment can be created in
| the same transaction as the order it belongs to.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->card = BankCard::factory()->create();

    $provisioned = app(GatewayProvisioner::class)->provision();
    $provisioned->application->update(['default_bank_card_id' => $this->card->id]);
});

it('creates a pending payment and returns the amount the customer must transfer', function () {
    $result = CardPay::createPayment([
        'amount' => 250_000,
        'external_order_id' => 'ORD-1',
        'customer' => ['name' => 'Sara', 'mobile' => '09120000000'],
    ], idempotencyKey: 'order-1');

    expect($result['status'])->toBe('pending')
        ->and($result['original_amount'])->toBe(250_000)
        // The token is what makes the deposit uniquely identifiable (§A1).
        ->and($result['payable_amount'])->toBe(250_000 + $result['token'])
        ->and($result['payment_url'])->toEndWith('/p/'.$result['payment_id']);

    $payment = Payment::query()->where('public_id', $result['payment_id'])->sole();

    expect($payment->external_order_id)->toBe('ORD-1')
        ->and($payment->customer_name)->toBe('Sara')
        ->and($payment->application_id)->toBe(app(GatewayProvisioner::class)->resolve()->id);
});

it('replays instead of allocating a second amount when the same idempotency key repeats', function () {
    $first = CardPay::createPayment(['amount' => 100_000], idempotencyKey: 'order-42');
    $second = CardPay::createPayment(['amount' => 100_000], idempotencyKey: 'order-42');

    expect($second['payment_id'])->toBe($first['payment_id'])
        ->and($second['idempotent_replay'])->toBeTrue()
        ->and(Payment::query()->count())->toBe(1);
});

it('generates a key when none is supplied, so a plain call still succeeds', function () {
    $first = CardPay::createPayment(['amount' => 100_000]);
    $second = CardPay::createPayment(['amount' => 100_000]);

    // Distinct keys mean distinct payments — and distinct payable amounts,
    // because two open payments may never share one on the same card.
    expect($second['payment_id'])->not->toBe($first['payment_id'])
        ->and($second['payable_amount'])->not->toBe($first['payable_amount']);
});

it('reads status back and reports settlement', function () {
    $created = CardPay::createPayment(['amount' => 50_000], idempotencyKey: 'order-7');

    expect(CardPay::status($created['payment_id'])['status'])->toBe('pending')
        ->and(CardPay::isPaid($created['payment_id']))->toBeFalse();

    CardPay::find($created['payment_id'])->forceFill([
        'status' => PaymentStatus::Paid,
        'paid_at' => now(),
    ])->save();

    expect(CardPay::isPaid($created['payment_id']))->toBeTrue();
});

it('cancels a pending payment', function () {
    $created = CardPay::createPayment(['amount' => 75_000], idempotencyKey: 'order-9');

    $canceled = CardPay::cancel($created['payment_id']);

    expect($canceled['status'])->toBe('canceled');
});

it('builds the hosted checkout URL', function () {
    expect(CardPay::checkoutUrl('PAY123'))->toEndWith('/p/PAY123');
});

it('participates in the host transaction, so a rolled-back order leaves no payment behind', function () {
    try {
        DB::transaction(function (): void {
            CardPay::createPayment(['amount' => 30_000], idempotencyKey: 'order-rollback');

            throw new RuntimeException('order failed after the payment was created');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Payment::query()->count())->toBe(0);
});

it('fails with an actionable message when the gateway was never provisioned', function () {
    DB::table('cp_applications')->delete();

    CardPay::createPayment(['amount' => 10_000], idempotencyKey: 'order-x');
})->throws(RuntimeException::class, 'cardpay:install');
