<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\ApiErrorCode;
use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\PaymentTokenReservation;
use CartBecart\CardPay\Services\Payments\PaymentService;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use CartBecart\CardPay\Tests\Support\RecordingWebhookEmitter;

/*
|--------------------------------------------------------------------------
| §FR-7 PaymentService — create / find / cancel
|--------------------------------------------------------------------------
|
| The merchant payment path. These prove the guarantees the whole gateway
| rests on: idempotent creation replays byte-identically (AC-2) and conflicts
| on a mismatched body, amounts/tokens/URLs are validated before any state is
| written, ownership is enforced with no cross-tenant leak, and cancellation
| obeys the state machine and applies the amount cooldown (AC-8).
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));
    config()->set('app.url', 'https://pay.example');

    // Observe emitted events without invoking any delivery machinery.
    $this->webhooks = new RecordingWebhookEmitter;
    $this->app->instance(WebhookEmitter::class, $this->webhooks);

    $this->card = BankCard::factory()->create();
    $this->merchant = Application::factory()->create([
        'default_bank_card_id' => $this->card->id,
        'token_digits' => 3,
        'payment_expiration_minutes' => 30,
    ]);

    // Resolve AFTER binding the recording emitter so it is injected.
    $this->service = app(PaymentService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A well-formed create body; per-test overrides merge over the defaults. */
function psValidBody(array $overrides = []): array
{
    return array_replace([
        'amount' => 250_000,
        'external_order_id' => 'A-123',
        'description' => 'Order A-123',
        'customer' => ['name' => 'Sara', 'mobile' => '09120000000', 'reference' => 'ref-9'],
        'metadata' => ['cart' => 'x'],
    ], $overrides);
}

describe('create()', function () {
    it('creates a pending payment with the §FR-7 presentment (201)', function () {
        $result = $this->service->create($this->merchant, psValidBody(), 'idem-key-0001');

        expect($result->created)->toBeTrue()
            ->and($result->status())->toBe(201);

        $data = $result->data;
        expect($data['status'])->toBe('pending')
            ->and($data['original_amount'])->toBe(250_000)
            ->and($data['token'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(999)
            ->and($data['payable_amount'])->toBe(250_000 + $data['token'])
            ->and($data['currency'])->toBe('IRR')
            ->and($data['idempotent_replay'])->toBeFalse()
            ->and($data['paid_at'])->toBeNull()
            ->and($data['payment_id'])->toStartWith('PAY')
            ->and($data['payment_url'])->toBe('https://pay.example/p/'.$data['payment_id'])
            ->and($data['expires_at'])->toBe(now()->addMinutes(30)->toIso8601String());
    });

    it('persists the payment and binds the reservation', function () {
        $data = $this->service->create($this->merchant, psValidBody(), 'idem-key-0002')->data;

        $payment = Payment::query()->where('public_id', $data['payment_id'])->firstOrFail();
        expect($payment->application_id)->toBe($this->merchant->id)
            ->and($payment->bank_card_id)->toBe($this->card->id)
            ->and($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->original_amount)->toBe(250_000)
            ->and($payment->external_order_id)->toBe('A-123')
            ->and($payment->customer_name)->toBe('Sara')
            ->and($payment->customer_mobile)->toBe('09120000000')
            ->and($payment->customer_reference)->toBe('ref-9')
            ->and($payment->metadata_json)->toBe(['cart' => 'x']);

        $reservation = PaymentTokenReservation::query()->where('payment_id', $payment->id)->firstOrFail();
        expect($reservation->payable_amount)->toBe($data['payable_amount'])
            ->and($reservation->token)->toBe($data['token'])
            ->and($reservation->active_key)->toBeTrue();
    });

    it('records exactly one payment.created event after commit', function () {
        $this->service->create($this->merchant, psValidBody(), 'idem-key-0003');

        expect($this->webhooks->events())->toBe([WebhookEventType::Created]);
    });

    it('replays byte-identically on the same key + same body (200, AC-2)', function () {
        $body = psValidBody();
        $first = $this->service->create($this->merchant, $body, 'idem-key-replay');
        $second = $this->service->create($this->merchant, $body, 'idem-key-replay');

        expect($second->created)->toBeFalse()
            ->and($second->status())->toBe(200)
            ->and($second->data['idempotent_replay'])->toBeTrue()
            ->and($second->data['payment_id'])->toBe($first->data['payment_id'])
            ->and($second->data['payable_amount'])->toBe($first->data['payable_amount']);

        // No second payment row, no second event.
        expect(Payment::query()->count())->toBe(1)
            ->and($this->webhooks->events())->toBe([WebhookEventType::Created]);
    });

    it('conflicts on the same key with a different body (409)', function () {
        $this->service->create($this->merchant, psValidBody(['amount' => 250_000]), 'idem-key-conflict');

        expectApiError(
            fn () => $this->service->create($this->merchant, psValidBody(['amount' => 999_999]), 'idem-key-conflict'),
            ApiErrorCode::IdempotencyConflict,
        );

        expect(Payment::query()->count())->toBe(1);
    });

    it('rejects a too-short idempotency key before writing anything (validation_failed)', function () {
        expectApiError(
            fn () => $this->service->create($this->merchant, psValidBody(), 'short'),
            ApiErrorCode::ValidationFailed,
        );

        expect(Payment::query()->count())->toBe(0);
    });

    it('rejects a non-positive or non-integer amount (invalid_amount)', function (mixed $amount) {
        expectApiError(
            fn () => $this->service->create($this->merchant, psValidBody(['amount' => $amount]), 'idem-key-amount'),
            ApiErrorCode::InvalidAmount,
        );

        expect(Payment::query()->count())->toBe(0);
    })->with([
        'zero' => [0],
        'negative' => [-5],
        'float' => [100.5],
        'numeric string' => ['250000'],
        'null' => [null],
    ]);
});

describe('create() bank card resolution', function () {
    it('falls back to the application default card when none supplied', function () {
        $data = $this->service->create($this->merchant, psValidBody(), 'idem-key-card1')->data;

        $payment = Payment::query()->where('public_id', $data['payment_id'])->firstOrFail();
        expect($payment->bank_card_id)->toBe($this->card->id);
    });

    it('uses an explicitly supplied active card', function () {
        $other = BankCard::factory()->create();

        $data = $this->service->create($this->merchant, psValidBody(['bank_card_id' => $other->id]), 'idem-key-card2')->data;

        $payment = Payment::query()->where('public_id', $data['payment_id'])->firstOrFail();
        expect($payment->bank_card_id)->toBe($other->id);
    });

    it('rejects an inactive card (invalid_bank_card)', function () {
        $inactive = BankCard::factory()->inactive()->create();

        expectApiError(
            fn () => $this->service->create($this->merchant, psValidBody(['bank_card_id' => $inactive->id]), 'idem-key-card3'),
            ApiErrorCode::InvalidBankCard,
        );
    });

    it('rejects a non-existent card (invalid_bank_card)', function () {
        expectApiError(
            fn () => $this->service->create($this->merchant, psValidBody(['bank_card_id' => 999_999]), 'idem-key-card4'),
            ApiErrorCode::InvalidBankCard,
        );
    });

    it('rejects when neither a card is supplied nor a default exists', function () {
        $app = Application::factory()->create(['default_bank_card_id' => null]);

        expectApiError(
            fn () => $this->service->create($app, psValidBody(), 'idem-key-card5'),
            ApiErrorCode::InvalidBankCard,
        );
    });
});

describe('create() return/callback URL allow-list (§SR-12)', function () {
    it('accepts an absolute https URL when the allow-list is empty (unrestricted)', function () {
        $result = $this->service->create(
            $this->merchant,
            psValidBody(['return_url' => 'https://any-shop.example/return']),
            'idem-key-url1',
        );

        expect($result->created)->toBeTrue();
    });

    it('rejects a non-absolute or non-http(s) URL (validation_failed)', function (string $url) {
        expectApiError(
            fn () => $this->service->create($this->merchant, psValidBody(['return_url' => $url]), 'idem-key-url2'),
            ApiErrorCode::ValidationFailed,
        );
    })->with([
        'relative' => ['/return'],
        'no scheme' => ['shop.example/return'],
        'ftp' => ['ftp://shop.example/f'],
        'javascript' => ['javascript:alert(1)'],
    ]);

    it('enforces the host allow-list: exact host and subdomain pass, others fail', function () {
        $app = Application::factory()->create([
            'default_bank_card_id' => $this->card->id,
            'allowed_domains' => "shop.example\nother.test",
        ]);

        expect($this->service->create($app, psValidBody(['callback_url' => 'https://shop.example/cb']), 'idem-key-url3')->created)
            ->toBeTrue();
        expect($this->service->create($app, psValidBody(['callback_url' => 'https://api.shop.example/cb']), 'idem-key-url4')->created)
            ->toBeTrue();

        expectApiError(
            fn () => $this->service->create($app, psValidBody(['callback_url' => 'https://evil.test/cb']), 'idem-key-url5'),
            ApiErrorCode::ValidationFailed,
        );
    });

    it('does not treat a bare suffix as a subdomain (notshop.example vs shop.example)', function () {
        $app = Application::factory()->create([
            'default_bank_card_id' => $this->card->id,
            'allowed_domains' => 'shop.example',
        ]);

        expectApiError(
            fn () => $this->service->create($app, psValidBody(['return_url' => 'https://notshop.example/x']), 'idem-key-url6'),
            ApiErrorCode::ValidationFailed,
        );
    });
});

describe('create() token pool', function () {
    it('surfaces token_pool_exhausted when the card pool is full', function () {
        $app = Application::factory()->create([
            'default_bank_card_id' => $this->card->id,
            'token_digits' => 1, // tokens 1..9 → 9 slots
        ]);

        for ($i = 1; $i <= 9; $i++) {
            $this->service->create($app, psValidBody(['amount' => 500_000]), 'idem-fill-'.$i);
        }

        expectApiError(
            fn () => $this->service->create($app, psValidBody(['amount' => 500_000]), 'idem-fill-10'),
            ApiErrorCode::TokenPoolExhausted,
        );
    });
});

describe('find()', function () {
    it('returns the application\'s own payment', function () {
        $created = $this->service->create($this->merchant, psValidBody(), 'idem-key-find1');

        $found = $this->service->find($this->merchant, $created->data['payment_id']);

        expect($found->public_id)->toBe($created->data['payment_id']);
    });

    it('hides another application\'s payment as payment_not_found (no cross-tenant leak)', function () {
        $created = $this->service->create($this->merchant, psValidBody(), 'idem-key-find2');
        $other = Application::factory()->create(['default_bank_card_id' => $this->card->id]);

        expectApiError(
            fn () => $this->service->find($other, $created->data['payment_id']),
            ApiErrorCode::PaymentNotFound,
        );
    });

    it('throws payment_not_found for an unknown id', function () {
        expectApiError(
            fn () => $this->service->find($this->merchant, 'PAYdoesnotexist'),
            ApiErrorCode::PaymentNotFound,
        );
    });
});

describe('cancel()', function () {
    it('cancels a pending payment, applies cooldown, and emits payment.canceled (AC-8)', function () {
        $publicId = $this->service->create($this->merchant, psValidBody(), 'idem-key-cancel1')->data['payment_id'];

        $result = $this->service->cancel($this->merchant, $publicId);

        expect($result->status())->toBe(200)
            ->and($result->data['status'])->toBe('canceled');

        $payment = Payment::query()->where('public_id', $publicId)->firstOrFail();
        expect($payment->status)->toBe(PaymentStatus::Canceled)
            ->and($payment->canceled_at)->not->toBeNull();

        // Cooldown scheduled on the (still-active) reservation.
        $reservation = PaymentTokenReservation::query()->where('payment_id', $payment->id)->firstOrFail();
        expect($reservation->release_at)->not->toBeNull();

        expect($this->webhooks->events())->toBe([WebhookEventType::Created, WebhookEventType::Canceled]);
    });

    it('refuses to cancel a non-pending payment (payment_cannot_be_canceled)', function () {
        $publicId = $this->service->create($this->merchant, psValidBody(), 'idem-key-cancel2')->data['payment_id'];

        // Settle it out from under the cancel via a direct write.
        Payment::query()->where('public_id', $publicId)->update([
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);

        expectApiError(
            fn () => $this->service->cancel($this->merchant, $publicId),
            ApiErrorCode::PaymentCannotBeCanceled,
        );
    });

    it('cannot cancel another application\'s payment (payment_not_found)', function () {
        $created = $this->service->create($this->merchant, psValidBody(), 'idem-key-cancel3');
        $other = Application::factory()->create(['default_bank_card_id' => $this->card->id]);

        expectApiError(
            fn () => $this->service->cancel($other, $created->data['payment_id']),
            ApiErrorCode::PaymentNotFound,
        );
    });
});
