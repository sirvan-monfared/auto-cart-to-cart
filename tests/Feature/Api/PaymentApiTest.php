<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Models\ApiNonce;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Security\Crypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use CartBecart\CardPay\Tests\Support\HmacRequestSigner;

/*
|--------------------------------------------------------------------------
| §11.2 Merchant API — end-to-end over HTTP
|--------------------------------------------------------------------------
|
| These drive the WHOLE stack — routing, the merchant.hmac gate (§A5 auth then
| §A7 rate limit), the controller, and the §11.1 envelope rendering — using the
| independent CartBecart\CardPay\Tests\Support\HmacRequestSigner to sign real requests. They prove
| the guarantees the payment path rests on: a valid signature is accepted and a
| creation returns 201 (idempotent replay 200, AC-2); ANY authentication failure
| returns a 401 envelope with NO business logic performed (AC-3 — no payment row,
| no nonce consumed); ownership is enforced (payment_not_found, no cross-tenant
| leak); cancel obeys the state machine (AC-8); and the per-app rate limit trips
| with 429 only AFTER auth has succeeded.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Freeze the clock so signed timestamps and the rate window are exact.
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));
    config()->set('app.url', 'https://pay.example');

    $this->card = BankCard::factory()->create();
    $this->merchant = Application::factory()->create([
        'default_bank_card_id' => $this->card->id,
        'token_digits' => 3,
        'payment_expiration_minutes' => 30,
    ]);

    // A live credential. The Encrypted cast stores the secret ciphered and hands
    // it back in plaintext, so the middleware verifies against exactly this value.
    $this->secret = 'merchant-secret-'.Str::random(12);
    $this->apiKey = ApplicationApiKey::query()->create([
        'application_id' => $this->merchant->id,
        'public_key' => 'pk_test_'.Str::lower(Str::random(20)),
        'secret_encrypted' => $this->secret,
        'secret_fingerprint' => Crypto::fingerprint($this->secret),
        'label' => 'Primary',
        'is_active' => true,
    ]);
    $this->publicKey = $this->apiKey->public_key;
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A well-formed create body; per-test overrides merge over the defaults. */
function apiBody(array $overrides = []): array
{
    return array_replace([
        'amount' => 250_000,
        'external_order_id' => 'A-123',
        'description' => 'Order A-123',
        'customer' => ['name' => 'Sara', 'mobile' => '09120000000'],
    ], $overrides);
}

/**
 * The four X-CardPay-* auth headers (plus JSON content headers) for a merchant
 * request, signed over the EXACT raw body. A fresh random nonce per call keeps
 * successive calls in one test from colliding; overrides can pin the nonce or
 * timestamp to exercise replay / staleness.
 *
 * @param  array<string, mixed>  $o
 * @return array<string, string>
 */
function mSign(string $method, string $path, string $rawBody, string $key, string $secret, array $o = []): array
{
    $nonce = (string) ($o['nonce'] ?? 'nonce_'.Str::random(20));
    $timestamp = (string) ($o['timestamp'] ?? now()->getTimestamp());

    $auth = (new HmacRequestSigner)->headers($method, $path, '', $rawBody, $key, $secret, $nonce, $timestamp);

    /** @var array<string, string> $extra */
    $extra = $o['headers'] ?? [];

    return array_replace(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $auth, $extra);
}

/**
 * Convert a header map to Symfony server vars so each request carries exactly
 * these headers with no cross-call accumulation (unlike withHeaders()).
 *
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function srv(array $headers): array
{
    $server = [];

    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        if ($key !== 'CONTENT_TYPE' && $key !== 'CONTENT_LENGTH') {
            $key = 'HTTP_'.$key;
        }
        $server[$key] = $value;
    }

    return $server;
}

/** Create a payment over HTTP with a valid signature and return its public id. */
function createPaymentOverHttp(object $ctx, string $idem): string
{
    $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
    $headers = mSign('POST', '/api/v1/payments', $raw, $ctx->publicKey, $ctx->secret, [
        'headers' => ['Idempotency-Key' => $idem],
    ]);

    $response = $ctx->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw);
    $response->assertStatus(201);

    return (string) $response->json('data.payment_id');
}

describe('POST /api/v1/payments (create)', function () {
    it('creates a pending payment and returns the §11.1 success envelope (201)', function () {
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
            'headers' => ['Idempotency-Key' => 'idem-http-create-1'],
        ]);

        $response = $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.original_amount', 250_000)
            ->assertJsonPath('data.currency', 'IRR')
            ->assertJsonPath('data.idempotent_replay', false);

        $id = (string) $response->json('data.payment_id');
        expect($id)->toStartWith('PAY')
            ->and($response->json('data.payable_amount'))->toBe(250_000 + $response->json('data.token'));

        $this->assertDatabaseHas('cp_payments', ['public_id' => $id, 'application_id' => $this->merchant->id]);
        // The valid request consumed exactly one nonce (§A5 step 6).
        expect(ApiNonce::query()->count())->toBe(1);
    });

    it('replays byte-identically on the same key + body (200, AC-2)', function () {
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $idem = ['headers' => ['Idempotency-Key' => 'idem-http-replay']];

        $first = $this->call('POST', '/api/v1/payments', [], [], [],
            srv(mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, $idem)), $raw);
        $second = $this->call('POST', '/api/v1/payments', [], [], [],
            srv(mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, $idem)), $raw);

        $first->assertStatus(201);
        $second->assertStatus(200)
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.payment_id', $first->json('data.payment_id'));

        // One payment despite two calls; two distinct nonces were consumed.
        expect(Payment::query()->count())->toBe(1)
            ->and(ApiNonce::query()->count())->toBe(2);
    });

    it('conflicts on the same key with a different body (409)', function () {
        $rawA = json_encode(apiBody(['amount' => 250_000]), JSON_THROW_ON_ERROR);
        $rawB = json_encode(apiBody(['amount' => 999_999]), JSON_THROW_ON_ERROR);
        $idem = ['headers' => ['Idempotency-Key' => 'idem-http-conflict']];

        $this->call('POST', '/api/v1/payments', [], [], [],
            srv(mSign('POST', '/api/v1/payments', $rawA, $this->publicKey, $this->secret, $idem)), $rawA)
            ->assertStatus(201);

        $this->call('POST', '/api/v1/payments', [], [], [],
            srv(mSign('POST', '/api/v1/payments', $rawB, $this->publicKey, $this->secret, $idem)), $rawB)
            ->assertStatus(409)
            ->assertJson(['success' => false])
            ->assertJsonPath('error.code', 'idempotency_conflict');

        expect(Payment::query()->count())->toBe(1);
    });

    it('rejects a missing / too-short Idempotency-Key (422 validation_failed)', function () {
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
            'headers' => ['Idempotency-Key' => 'short'],
        ]);

        $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        expect(Payment::query()->count())->toBe(0);
    });
});

describe('authentication (§A5 / AC-3)', function () {
    it('rejects a request with a missing auth header and performs no business logic', function () {
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
            'headers' => ['Idempotency-Key' => 'idem-http-noauth'],
        ]);
        unset($headers['X-CardPay-Signature']);

        $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw)
            ->assertStatus(401)
            ->assertJson(['success' => false])
            ->assertJsonPath('error.code', 'invalid_api_key');

        // No payment, and the nonce was never consumed (auth failed at step 1).
        expect(Payment::query()->count())->toBe(0)
            ->and(ApiNonce::query()->count())->toBe(0);
    });

    it('rejects a forged signature with invalid_signature and stores no nonce', function () {
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
            'headers' => ['Idempotency-Key' => 'idem-http-forged'],
        ]);
        $headers['X-CardPay-Signature'] = str_repeat('0', 64);

        $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_signature');

        expect(Payment::query()->count())->toBe(0)
            ->and(ApiNonce::query()->count())->toBe(0);
    });

    it('rejects a body altered after signing (the body is covered)', function () {
        $signedBody = json_encode(apiBody(['amount' => 250_000]), JSON_THROW_ON_ERROR);
        $sentBody = json_encode(apiBody(['amount' => 111_111]), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $signedBody, $this->publicKey, $this->secret, [
            'headers' => ['Idempotency-Key' => 'idem-http-tamper'],
        ]);

        $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $sentBody)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_signature');
    });

    it('rejects a stale timestamp with invalid_signature', function () {
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
            'timestamp' => (string) (now()->getTimestamp() - 301),
            'headers' => ['Idempotency-Key' => 'idem-http-stale'],
        ]);

        $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_signature');
    });

    it('rejects a replayed nonce with invalid_signature', function () {
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);

        // First use of this nonce succeeds; the id differs per call via Idempotency-Key.
        $this->call('POST', '/api/v1/payments', [], [], [],
            srv(mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
                'nonce' => 'fixed-nonce-abc123', 'headers' => ['Idempotency-Key' => 'idem-http-nonce-1'],
            ])), $raw)->assertStatus(201);

        // Reusing the SAME nonce (fresh key) must be rejected as a replay.
        $this->call('POST', '/api/v1/payments', [], [], [],
            srv(mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
                'nonce' => 'fixed-nonce-abc123', 'headers' => ['Idempotency-Key' => 'idem-http-nonce-2'],
            ])), $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_signature');
    });

    it('rejects an unknown key with invalid_api_key', function () {
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $raw, 'pk_unknown', $this->secret, [
            'headers' => ['Idempotency-Key' => 'idem-http-unknown'],
        ]);

        $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    });

    it('rejects a revoked key with invalid_api_key', function () {
        $this->apiKey->forceFill(['revoked_at' => now()])->save();
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
            'headers' => ['Idempotency-Key' => 'idem-http-revoked'],
        ]);

        $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    });

    it('rejects a key belonging to an inactive application with invalid_api_key', function () {
        $this->merchant->forceFill(['is_active' => false])->save();
        $raw = json_encode(apiBody(), JSON_THROW_ON_ERROR);
        $headers = mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
            'headers' => ['Idempotency-Key' => 'idem-http-inactive-app'],
        ]);

        $this->call('POST', '/api/v1/payments', [], [], [], srv($headers), $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    });
});

describe('GET / verify / cancel', function () {
    it('returns a payment for its owner (GET, 200)', function () {
        $id = createPaymentOverHttp($this, 'idem-http-show');

        $path = "/api/v1/payments/{$id}";
        $this->call('GET', $path, [], [], [], srv(mSign('GET', $path, '', $this->publicKey, $this->secret)), '')
            ->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.payment_id', $id)
            ->assertJsonPath('data.status', 'pending');
    });

    it('returns the same presentment via POST verify (200)', function () {
        $id = createPaymentOverHttp($this, 'idem-http-verify');

        $path = "/api/v1/payments/{$id}/verify";
        $this->call('POST', $path, [], [], [], srv(mSign('POST', $path, '', $this->publicKey, $this->secret)), '')
            ->assertStatus(200)
            ->assertJsonPath('data.payment_id', $id);
    });

    it('hides another application\'s payment as payment_not_found (no cross-tenant leak)', function () {
        $id = createPaymentOverHttp($this, 'idem-http-foreign');

        // A second application with its own live credential.
        $other = Application::factory()->create(['default_bank_card_id' => $this->card->id]);
        $otherSecret = 'other-secret-'.Str::random(12);
        $otherKey = ApplicationApiKey::query()->create([
            'application_id' => $other->id,
            'public_key' => 'pk_other_'.Str::lower(Str::random(16)),
            'secret_encrypted' => $otherSecret,
            'secret_fingerprint' => Crypto::fingerprint($otherSecret),
            'is_active' => true,
        ]);

        $path = "/api/v1/payments/{$id}";
        $this->call('GET', $path, [], [], [], srv(mSign('GET', $path, '', $otherKey->public_key, $otherSecret)), '')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'payment_not_found');
    });

    it('cancels a pending payment (200, AC-8)', function () {
        $id = createPaymentOverHttp($this, 'idem-http-cancel');

        $path = "/api/v1/payments/{$id}/cancel";
        $this->call('POST', $path, [], [], [], srv(mSign('POST', $path, '', $this->publicKey, $this->secret)), '')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'canceled');

        $this->assertDatabaseHas('cp_payments', ['public_id' => $id, 'status' => PaymentStatus::Canceled->value]);
    });

    it('refuses to cancel a non-pending payment (409 payment_cannot_be_canceled)', function () {
        $id = createPaymentOverHttp($this, 'idem-http-cancel-paid');

        Payment::query()->where('public_id', $id)->update([
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);

        $path = "/api/v1/payments/{$id}/cancel";
        $this->call('POST', $path, [], [], [], srv(mSign('POST', $path, '', $this->publicKey, $this->secret)), '')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'payment_cannot_be_canceled');
    });
});

describe('rate limiting (§A7)', function () {
    it('trips 429 after the per-application cap, only once auth has succeeded', function () {
        config()->set('cardpay.rate_limits.api', 2);

        // Two authenticated creates fit the window; the third is rate-limited
        // BEFORE reaching the controller, so no third payment is created (AC-3).
        foreach (['rl-1', 'rl-2'] as $key) {
            $raw = json_encode(apiBody(['external_order_id' => $key]), JSON_THROW_ON_ERROR);
            $this->call('POST', '/api/v1/payments', [], [], [],
                srv(mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
                    'headers' => ['Idempotency-Key' => 'idem-http-'.$key],
                ])), $raw)->assertStatus(201);
        }

        $raw = json_encode(apiBody(['external_order_id' => 'rl-3']), JSON_THROW_ON_ERROR);
        $blocked = $this->call('POST', '/api/v1/payments', [], [], [],
            srv(mSign('POST', '/api/v1/payments', $raw, $this->publicKey, $this->secret, [
                'headers' => ['Idempotency-Key' => 'idem-http-rl-3'],
            ])), $raw);

        $blocked->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limit_exceeded')
            ->assertHeader('Retry-After');

        expect(Payment::query()->count())->toBe(2);
    });
});
