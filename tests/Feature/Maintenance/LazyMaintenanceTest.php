<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Models\ApiNonce;
use CartBecart\CardPay\Models\DeviceNonce;
use CartBecart\CardPay\Models\IdempotencyKey;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\PaymentTokenReservation;
use CartBecart\CardPay\Models\RateLimit;
use CartBecart\CardPay\Services\Maintenance\LazyMaintenance;
use CartBecart\CardPay\Services\Payments\PaymentExpiryService;
use CartBecart\CardPay\Services\Payments\PaymentStateMachine;
use CartBecart\CardPay\Services\Payments\TokenAllocator;
use CartBecart\CardPay\Tests\Support\RecordingWebhookEmitter;
use CartBecart\CardPay\Tests\Support\SpyWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->freezeTime();
    $this->allocator = new TokenAllocator;
    $this->processor = new SpyWebhookProcessor;
    $this->maintenance = new LazyMaintenance(
        new PaymentExpiryService(new PaymentStateMachine, $this->allocator, new RecordingWebhookEmitter),
        $this->allocator,
        $this->processor,
    );
});

it('expires overdue payments as part of a budgeted run', function () {
    $due = Payment::factory()->create([
        'application_id' => 1,
        'bank_card_id' => 1,
        'status' => PaymentStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    $this->maintenance->runBudgeted();

    expect($due->fresh()->status)->toBe(PaymentStatus::Expired);
});

it('releases reservations whose cooldown has elapsed and keeps the rest', function () {
    $elapsed = PaymentTokenReservation::query()->create([
        'bank_card_id' => 1, 'payable_amount' => 100, 'token' => 1,
        'active_key' => true, 'release_at' => now()->subMinute(),
    ]);
    $future = PaymentTokenReservation::query()->create([
        'bank_card_id' => 1, 'payable_amount' => 200, 'token' => 2,
        'active_key' => true, 'release_at' => now()->addMinutes(5),
    ]);
    $active = PaymentTokenReservation::query()->create([
        'bank_card_id' => 1, 'payable_amount' => 300, 'token' => 3,
        'active_key' => true, 'release_at' => null,
    ]);

    $this->maintenance->runBudgeted();

    expect($elapsed->fresh()->active_key)->toBeNull()      // freed
        ->and($future->fresh()->active_key)->toBeTrue()    // not yet due
        ->and($active->fresh()->active_key)->toBeTrue();   // never cooled down
});

it('purges expired nonces, rate-limit buckets, and idempotency keys but keeps live ones', function () {
    $now = now();

    ApiNonce::query()->create(['application_id' => 1, 'nonce' => 'dead-api', 'expires_at' => $now->copy()->subMinute()]);
    ApiNonce::query()->create(['application_id' => 1, 'nonce' => 'live-api', 'expires_at' => $now->copy()->addMinute()]);

    DeviceNonce::query()->create(['device_id' => 1, 'nonce' => 'dead-dev', 'expires_at' => $now->copy()->subMinute()]);
    DeviceNonce::query()->create(['device_id' => 1, 'nonce' => 'live-dev', 'expires_at' => $now->copy()->addMinute()]);

    RateLimit::query()->create(['scope' => 'api', 'rate_key' => 'dead', 'window_start' => 1, 'attempts' => 1, 'expires_at' => $now->copy()->subMinute()]);
    RateLimit::query()->create(['scope' => 'api', 'rate_key' => 'live', 'window_start' => 2, 'attempts' => 1, 'expires_at' => $now->copy()->addMinute()]);

    IdempotencyKey::query()->create(['application_id' => 1, 'idempotency_key' => 'dead', 'request_hash' => str_repeat('a', 64), 'expires_at' => $now->copy()->subMinute()]);
    IdempotencyKey::query()->create(['application_id' => 1, 'idempotency_key' => 'live', 'request_hash' => str_repeat('b', 64), 'expires_at' => $now->copy()->addMinute()]);

    $this->maintenance->runBudgeted();

    expect(ApiNonce::query()->pluck('nonce')->all())->toBe(['live-api'])
        ->and(DeviceNonce::query()->pluck('nonce')->all())->toBe(['live-dev'])
        ->and(RateLimit::query()->pluck('rate_key')->all())->toBe(['live'])
        ->and(IdempotencyKey::query()->pluck('idempotency_key')->all())->toBe(['live']);
});

it('drives the webhook processor with the configured budget', function () {
    $this->maintenance->runBudgeted();

    expect($this->processor->calls)->toBe(1)
        ->and($this->processor->lastLimit)->toBe(config('cardpay.maintenance.webhook_process_due'));
});
