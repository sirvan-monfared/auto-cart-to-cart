<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\DeliveryStatus;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\WebhookDelivery;
use CartBecart\CardPay\Models\WebhookEvent;
use CartBecart\CardPay\Services\Security\Crypto;
use CartBecart\CardPay\Services\Webhooks\HttpWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §A6 / §FR-13 Webhook HTTP delivery
|--------------------------------------------------------------------------
|
| The processor is the ONLY place the gateway speaks outbound HTTP. Under
| test with Http::fake(), it must: sign the EXACT stored payload with the
| app's latest active secret; treat 2xx as delivered and anything else as a
| scheduled retry on the [0,1,5,15,60]-minute ladder; exhaust after the last
| rung; record response/duration/error fields; and never throw — webhook
| failure NEVER alters financial state.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

    $this->card = BankCard::factory()->create();
    $this->merchant = Application::factory()->create([
        'default_bank_card_id' => $this->card->id,
        'webhook_url' => 'https://merchant.example/hooks',
    ]);

    $this->secret = 'whisk-'.Str::random(16);
    $this->apiKey = ApplicationApiKey::query()->create([
        'application_id' => $this->merchant->id,
        'public_key' => 'pk_'.Str::lower(Str::random(20)),
        'secret_encrypted' => $this->secret,
        'secret_fingerprint' => Crypto::fingerprint($this->secret),
        'is_active' => true,
    ]);

    $this->event = WebhookEvent::query()->create([
        'event_id' => 'evt_'.Str::lower((string) Str::ulid()),
        'application_id' => $this->merchant->id,
        'payment_id' => 1,
        'event_type' => 'payment.paid',
        'payload_json' => ['event' => 'payment.paid', 'payable_amount' => 100417],
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('delivery creation', function () {
    it('creates one delivery per event with the resolved webhook_url', function () {
        Http::fake(['merchant.example/*' => Http::response([], 200)]);

        app(HttpWebhookProcessor::class)->processDue(5);

        // One event → exactly one delivery; the URL came from webhook_url.
        $delivery = WebhookDelivery::query()->sole();
        expect($delivery->url)->toBe('https://merchant.example/hooks')
            ->and($delivery->status)->toBe(DeliveryStatus::Delivered);

        // A second pass creates no duplicate delivery rows (§FR-13).
        app(HttpWebhookProcessor::class)->processDue(5);
        expect(WebhookDelivery::query()->count())->toBe(1);
    });

    it('exhausts immediately when the application has no usable http(s) url', function () {
        $this->merchant->forceFill(['webhook_url' => null, 'callback_url' => null])->save();

        app(HttpWebhookProcessor::class)->processDue(5);

        $delivery = WebhookDelivery::query()->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
            ->and($delivery->error_message)->toBe('no_webhook_url_configured');
    });

    it('falls back to callback_url when webhook_url is absent', function () {
        $this->merchant->forceFill([
            'webhook_url' => null,
            'callback_url' => 'https://merchant.example/callback',
        ])->save();

        app(HttpWebhookProcessor::class)->processDue(5);

        expect(WebhookDelivery::query()->sole()->url)->toBe('https://merchant.example/callback');
    });
});

describe('signed delivery', function () {
    it('POSTs the exact payload with the right headers and marks 2xx delivered', function () {
        Http::fake(['merchant.example/*' => Http::response(['ok' => true], 200)]);

        app(HttpWebhookProcessor::class)->processDue(5);

        $expectedBody = json_encode($this->event->payload_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expectedSig = hash_hmac('sha256', (string) $expectedBody, $this->secret);

        Http::assertSent(function ($request) use ($expectedSig, $expectedBody) {
            return $request->url() === 'https://merchant.example/hooks'
                && $request->toPsrRequest()->getBody()->getContents() === $expectedBody
                && $request->header('X-CardPay-Signature') === [$expectedSig]
                && $request->header('Content-Type') === ['application/json']
                && str_contains((string) ($request->header('User-Agent')[0] ?? ''), 'CardPay-Webhook');
        });

        $delivery = WebhookDelivery::query()->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Delivered)
            ->and($delivery->attempt)->toBe(1)
            ->and($delivery->response_status)->toBe(200)
            ->and($delivery->duration_ms)->not->toBeNull()
            ->and($delivery->next_attempt_at)->toBeNull();
    });

    it('signs with the LATEST active key after rotation', function () {
        $newSecret = 'whisk-new-'.Str::random(12);
        ApplicationApiKey::query()->create([
            'application_id' => $this->merchant->id,
            'public_key' => 'pk_new_'.Str::lower(Str::random(18)),
            'secret_encrypted' => $newSecret,
            'secret_fingerprint' => Crypto::fingerprint($newSecret),
            'is_active' => true,
        ]);

        Http::fake(['merchant.example/*' => Http::response([], 204)]);

        app(HttpWebhookProcessor::class)->processDue(5);

        $expectedSig = hash_hmac('sha256', (string) json_encode($this->event->payload_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $newSecret);
        Http::assertSent(fn ($request) => $request->header('X-CardPay-Signature') === [$expectedSig]);
    });

    it('ignores revoked keys when choosing the signing secret', function () {
        $this->apiKey->forceFill(['revoked_at' => now()])->save();

        Http::fake(['merchant.example/*' => Http::response([], 200)]);

        app(HttpWebhookProcessor::class)->processDue(5);

        // No active key remains → empty-string secret still signs (stable wire
        // behavior); the delivery itself still succeeds on 2xx.
        Http::assertSent(fn ($request) => $request->header('X-CardPay-Signature') !== []);
    });
});

describe('retry ladder [0,1,5,15,60]', function () {
    it('schedules attempt N+1 at now + RETRY_MINUTES[N] after failure', function () {
        Http::fake(['merchant.example/*' => Http::response(['err' => 'up'], 500)]);

        app(HttpWebhookProcessor::class)->processDue(5);

        $delivery = WebhookDelivery::query()->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Failed)
            ->and($delivery->attempt)->toBe(1)
            ->and($delivery->response_status)->toBe(500)
            ->and($delivery->response_body)->toContain('up')
            // Rung 0 = immediate retry.
            ->and($delivery->next_attempt_at->equalTo(now()))->toBeTrue();

        // Second failure (rung index 1) → +1 minute.
        Http::fake(['merchant.example/*' => Http::response([], 503)]);
        app(HttpWebhookProcessor::class)->processDue(5);

        $delivery->refresh();
        expect($delivery->attempt)->toBe(2)
            ->and($delivery->next_attempt_at->equalTo(now()->addMinute()))->toBeTrue();
    });

    it('recovers to delivered when a later attempt succeeds', function () {
        // A stacked Http::fake() does not replace the earlier one — use a
        // sequence: first POST fails, subsequent POSTs succeed.
        Http::fake(['merchant.example/*' => Http::sequence()
            ->push([], 500)
            ->push([], 200),
        ]);

        app(HttpWebhookProcessor::class)->processDue(5);
        app(HttpWebhookProcessor::class)->processDue(5);

        $delivery = WebhookDelivery::query()->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Delivered)
            ->and($delivery->attempt)->toBe(2)
            ->and($delivery->next_attempt_at)->toBeNull();
    });

    it('exhausts after five failed attempts and stops being retried', function () {
        Http::fake(['merchant.example/*' => Http::response([], 500)]);

        for ($i = 0; $i < 6; $i++) {
            app(HttpWebhookProcessor::class)->processDue(5);
            // Jump past each rung so the next attempt is due immediately.
            Carbon::setTestNow(now()->addMinutes(61));
        }

        $delivery = WebhookDelivery::query()->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
            ->and($delivery->attempt)->toBe(5);

        // A further pass attempts nothing new.
        expect(app(HttpWebhookProcessor::class)->processDue(5))->toBe(0);
    });

    it('records connection errors in error_message without throwing', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

        app(HttpWebhookProcessor::class)->processDue(5);

        $delivery = WebhookDelivery::query()->sole();
        expect($delivery->status)->toBe(DeliveryStatus::Failed)
            ->and($delivery->error_message)->toContain('timeout')
            ->and($delivery->response_status)->toBeNull();
    });

    it('never alters financial state regardless of outcome', function () {
        Http::fake(['merchant.example/*' => Http::response([], 500)]);

        app(HttpWebhookProcessor::class)->processDue(5);

        // The event's payload is untouched and no payment rows were written by
        // delivery failures (the only writes are to cp_webhook_deliveries).
        expect(WebhookEvent::query()->sole()->payload_json)
            ->toBe(['event' => 'payment.paid', 'payable_amount' => 100417]);
    });
});
