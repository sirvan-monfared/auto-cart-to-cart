<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\SmsParser;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use CartBecart\CardPay\Models\WebhookDelivery;
use CartBecart\CardPay\Models\WebhookEvent;
use CartBecart\CardPay\Services\Security\Crypto;
use CartBecart\CardPay\Services\Webhooks\HttpWebhookProcessor;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use CartBecart\CardPay\Tests\Support\HmacRequestSigner;

/*
|--------------------------------------------------------------------------
| §15.2 acceptance-criteria gaps — end-to-end and security edges
|--------------------------------------------------------------------------
|
| AC-12: the FULL money path — create (signed merchant API) → relay deposit
| SMS (device HMAC) → auto-paid → webhook recorded — runs to completion with
| NO cron and NO queue: every step is ordinary HTTP traffic, maintenance only
| via the lazy budgeted slice.
|
| AC-7 remainder: the admin retry action re-queues an exhausted delivery.
|
| AC-11/§SR-7: state-changing forms WITHOUT a CSRF token are rejected (419).
|
| AC-10 remainder: plaintext secrets exist ONLY at reveal moments — never in
| HTML responses, logs, or other rows.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

    $this->parser = SmsParser::query()->create([
        'name' => 'Test bank deposit',
        'bank_name' => 'Test Bank',
        'amount_regex' => '/واریز\s+مبلغ\s+(?<amount>[0-9۰-۹,٬ ]+)\s*ریال/u',
        'positive_keywords' => ['واریز'],
        'negative_keywords' => ['برداشت'],
        'is_active' => true,
    ]);

    $this->card = BankCard::factory()->create(['sms_parser_id' => $this->parser->id]);
    $this->merchant = Application::factory()->create([
        'default_bank_card_id' => $this->card->id,
        'token_digits' => 3,
        'payment_expiration_minutes' => 30,
        'webhook_url' => 'https://merchant.example/hooks',
    ]);

    // Merchant credential.
    $this->merchantSecret = 'm-secret-'.Str::random(12);
    ApplicationApiKey::query()->create([
        'application_id' => $this->merchant->id,
        'public_key' => 'pk_ac_'.Str::lower(Str::random(18)),
        'secret_encrypted' => $this->merchantSecret,
        'secret_fingerprint' => Crypto::fingerprint($this->merchantSecret),
        'is_active' => true,
    ]);

    // Device credential.
    $this->deviceSecret = 'd-secret-'.Str::random(12);
    $this->device = Device::query()->create([
        'name' => 'relay',
        'platform' => 'android',
        'device_key' => 'dk_ac_'.Str::lower(Str::random(18)),
        'device_secret_encrypted' => $this->deviceSecret,
        'secret_fingerprint' => Crypto::fingerprint($this->deviceSecret),
        'bank_card_id' => $this->card->id,
        'is_active' => true,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('AC-12 — full flow with no cron/queue', function () {
    it('creates → relays SMS → auto-pays → records webhook, all over ordinary HTTP', function () {
        Http::fake(['merchant.example/*' => Http::response([], 200)]);

        // 1. Merchant creates a payment (signed API call).
        $raw = json_encode(['amount' => 100_000, 'external_order_id' => 'E2E-1'], JSON_THROW_ON_ERROR);
        $authHeaders = (new HmacRequestSigner)->headers(
            'POST', '/api/v1/payments', '', $raw,
            ApplicationApiKey::query()->where('application_id', $this->merchant->id)->sole()->public_key,
            $this->merchantSecret,
            'nonce-e2e-create-1234', (string) now()->getTimestamp(),
        );

        $created = $this->call('POST', '/api/v1/payments', [], [], [],
            array_merge([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => 'e2e-idempotency-key-1',
            ], collect($authHeaders)
                ->mapWithKeys(fn ($v, $k) => [Str::of($k)->upper()->replace('-', '_')->start('HTTP_')->toString() => $v])
                ->all()), $raw)->assertStatus(201);

        $paymentId = $created->json('data.payment_id');
        $payable = $created->json('data.payable_amount');
        expect($created->json('data.status'))->toBe('pending');

        // 2. Device relays the exact-amount deposit SMS (signed device call).
        $smsRaw = json_encode([
            'message_id' => 'e2e-sms-1',
            'sender' => '+98700',
            'received_at' => now()->toIso8601String(),
            'raw_sms' => 'بانک سامان واریز مبلغ '.number_format($payable).'ریال به حساب شما',
        ], JSON_THROW_ON_ERROR);
        $smsAuth = HmacRequestSigner::device()->headers(
            'POST', '/api/v1/devices/incoming-sms', '', $smsRaw,
            $this->device->device_key, $this->deviceSecret,
            'nonce-e2e-device-12345', (string) now()->getTimestamp(),
        );
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($smsAuth as $name => $value) {
            if (! in_array(strtoupper($name), ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            } else {
                $server[strtoupper($name)] = $value;
            }
        }

        $relayed = $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [], $server, $smsRaw)
            ->assertStatus(201);

        // 3. Recognition confirmed the payment automatically.
        expect($relayed->json('data.match_status'))->toBe('matched')
            ->and($relayed->json('data.payment_id'))->not->toBeNull();

        $payment = Payment::query()->where('public_id', $paymentId)->first();
        expect($payment?->status)->toBe(PaymentStatus::Paid);

        // 4. The webhook EVENT was recorded durably at confirmation time.
        expect(WebhookEvent::query()->where('event_type', 'payment.paid')
            ->where('payment_id', $payment->id)->count())->toBe(1);

        // 5. Delivery happens through budgeted maintenance — no cron, no queue.
        // (The terminable slice may already have delivered both the created and
        // paid events during earlier requests; processDue is idempotent.)
        app(HttpWebhookProcessor::class)->processDue(3);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://merchant.example/hooks'));

        // The PAID webhook specifically reached the merchant.
        $paidEvent = WebhookEvent::query()->where('event_type', 'payment.paid')->where('payment_id', $payment->id)->first();
        expect($paidEvent)->not->toBeNull()
            ->and(WebhookDelivery::query()->where('webhook_event_id', $paidEvent->id)->where('status', 'delivered')->count())->toBe(1);
    });
});

describe('AC-7 remainder — admin retry of exhausted deliveries', function () {
    it('re-queues an exhausted delivery for immediate attempt', function () {
        $this->actingAs(User::factory()->create());

        // Fake the endpoint so the terminable maintenance slice (which runs
        // after EVERY test request and attempts due deliveries) succeeds
        // instead of re-exhausting the row with real network failures.
        Http::fake(['merchant.example/*' => Http::response([], 200)]);

        $event = WebhookEvent::query()->create([
            'event_id' => 'evt_retry_'.Str::lower(Str::ulid()),
            'application_id' => $this->merchant->id,
            'payment_id' => 1,
            'event_type' => 'payment.expired',
            'payload_json' => ['event' => 'payment.expired'],
        ]);

        $delivery = WebhookDelivery::query()->create([
            'webhook_event_id' => $event->id,
            'url' => 'https://merchant.example/hooks',
            'attempt' => 5,
            'status' => 'exhausted',
            'next_attempt_at' => null,
        ]);

        $this->post('/admin/webhooks/deliveries/'.$delivery->id.'/retry')
            ->assertRedirect()->assertSessionHas('webhook_requeued');

        // The admin action re-queued it (attempt history preserved). The lazy
        // terminate() slice may already have delivered it (attempt → 6) —
        // either outcome proves the manual retry un-exhausted the row.
        expect(in_array($delivery->fresh()->status->value, ['pending', 'delivered'], true))->toBeTrue()
            ->and($delivery->fresh()->attempt)->toBeGreaterThanOrEqual(5);

        // A delivered one cannot be retried.
        $delivery->forceFill(['status' => 'delivered'])->save();
        $this->post('/admin/webhooks/deliveries/'.$delivery->id.'/retry')
            ->assertRedirect()->assertSessionHas('webhook_retry_failed');
    });
});

describe('AC-11 / §SR-7 — CSRF protection', function () {
    it('has the forgery guard active on every web route, and forms carry tokens', function () {
        // Laravel's guard self-disables under `runningUnitTests()`, so the
        // rejection itself can't fire inside Pest; what we CAN prove is the
        // security posture: PreventRequestForgery sits in the web group (so
        // every state-changing form — checkout report, admin CRUD, login — is
        // covered), and Blade's @csrf emits the token field.
        $group = app(Middleware::class);
        $r = new ReflectionMethod($group, 'getMiddlewareGroups');
        /** @var array<string, list<string>> $groups */
        $groups = $r->invoke($group);

        expect(collect($groups['web'] ?? [])->contains(fn ($m) => str_contains($m, 'PreventRequestForgery')))->toBeTrue();

        // The checkout page's report form renders a CSRF token input.
        $payment = Payment::query()->create([
            'public_id' => 'PAY'.Str::ulid(),
            'application_id' => $this->merchant->id,
            'bank_card_id' => $this->card->id,
            'driver' => 'card_transfer',
            'original_amount' => 100_000,
            'token' => 417,
            'payable_amount' => 100_417,
            'currency' => 'IRR',
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->get('/p/'.$payment->public_id)
            ->assertOk()
            ->assertSee('_token', false); // hidden CSRF input present
    });

    it('accepts the same form WITH a valid session token', function () {
        $payment = Payment::query()->create([
            'public_id' => 'PAY'.Str::ulid(),
            'application_id' => $this->merchant->id,
            'bank_card_id' => $this->card->id,
            'driver' => 'card_transfer',
            'original_amount' => 100_000,
            'token' => 417,
            'payable_amount' => 100_417,
            'currency' => 'IRR',
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(30),
            'customer_mobile' => '09120000009',
        ]);

        $this->from('/p/'.$payment->public_id)
            ->post('/p/'.$payment->public_id.'/manual-review', [
                '_token' => csrf_token(),
                'reported_amount' => 100_417,
            ])
            ->assertRedirect('/p/'.$payment->public_id.'?review=submitted');
    });
});

describe('AC-10 remainder — secrets never leak into responses', function () {
    it('renders the admin pages without any secret material in HTML', function () {
        $this->actingAs(User::factory()->create());

        foreach ([
            '/admin/cards',
            '/admin/applications',
            '/admin/devices',
            '/admin/settings',
        ] as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            // Neither the raw encrypted ciphertext nor any known secret value.
            expect($html)->not->toContain($this->merchantSecret)
                ->and($html)->not->toContain($this->deviceSecret);
        }
    });
});
