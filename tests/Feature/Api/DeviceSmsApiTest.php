<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Enums\ParseStatus;
use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\DeviceNonce;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\SmsParser;
use CartBecart\CardPay\Services\Security\Crypto;
use CartBecart\CardPay\Services\Sms\SmsIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use CartBecart\CardPay\Tests\Support\HmacRequestSigner;

/*
|--------------------------------------------------------------------------
| §11.2 #8–9 Device SMS relay — end-to-end over HTTP
|--------------------------------------------------------------------------
|
| These exercise the recognition path's FRONT DOOR: routing → device gates →
| controller validation → ingestion (dedupe, sender gate, parser resolution) →
| parse pipeline → fail-safe matching. Signed requests use the independent
| CartBecart\CardPay\Tests\Support\HmacRequestSigner with the X-Device-* scheme.
|
| The guarantees under test: auth failures change NO state (no SMS row, no
| nonce consumed); a fresh message is 201 and a replay is 200/duplicate:true
| with the ORIGINAL payment id; the happy path confirms exactly one pending
| payment; ambiguous deposits escalate to manual review and pay NOTHING; late
| deposits on expired payments stay unmatched; shortcut defaults apply.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

    // Card + active parser whose rules match the sample deposit text below.
    $this->parser = SmsParser::query()->create([
        'name' => 'Test bank deposit',
        'bank_name' => 'Test Bank',
        'sender_pattern' => '/^98700077$/',
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
    ]);

    $this->secret = 'device-secret-'.Str::random(12);
    $this->device = Device::query()->create([
        'name' => 'Relay phone',
        'platform' => 'android',
        'device_key' => 'dk_'.Str::lower(Str::random(20)),
        'device_secret_encrypted' => $this->secret,
        'secret_fingerprint' => Crypto::fingerprint($this->secret),
        'bank_card_id' => $this->card->id,
        'is_active' => true,
    ]);
    $this->deviceKey = $this->device->device_key;

    // A pending payment to be confirmed by an exact-amount deposit SMS.
    $this->payableAmount = 250_417;
    $this->payment = createPendingPayment($this->merchant, $this->card, $this->payableAmount);

    $this->depositText = 'بانک سامان واریز مبلغ '.number_format($this->payableAmount).'ریال به حساب شما';
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A pending payment with its token reservation bound (mirrors what
 * PaymentService does), so the matcher sees realistic candidates.
 */
function createPendingPayment(Application $app, BankCard $card, int $amount): Payment
{
    $token = random_int(1, 999);
    $now = now();

    return Payment::query()->create([
        'public_id' => 'PAY'.Str::ulid(),
        'application_id' => $app->id,
        'bank_card_id' => $card->id,
        'driver' => 'card_transfer',
        'original_amount' => $amount - $token,
        'token' => $token,
        'payable_amount' => $amount,
        'currency' => 'IRR',
        'status' => 'pending',
        'expires_at' => $now->copy()->addMinutes(30),
    ]);
}

/** A valid deposit body from the bank's sender number. */
function smsBody(array $overrides = []): array
{
    return array_replace([
        'message_id' => 'sms-'.Str::random(16),
        'sender' => '98700077',
        'received_at' => now()->toIso8601String(),
        'raw_sms' => '',
    ], $overrides);
}

/**
 * The four X-Device-* headers for a signed device request (§A5 canonical form,
 * independently built). Overrides may pin nonce/timestamp for replay tests.
 *
 * @param  array<string, mixed>  $o
 * @return array<string, string>
 */
function dSign(string $path, string $rawBody, string $key, string $secret, array $o = []): array
{
    // The DEVICE header set (X-Device-*), not the merchant default.
    $auth = HmacRequestSigner::device()->headers(
        'POST', $path, '', $rawBody, $key, $secret,
        (string) ($o['nonce'] ?? 'nonce_'.Str::random(20)),
        (string) ($o['timestamp'] ?? now()->getTimestamp()),
    );

    /** @var array<string, string> $extra */
    $extra = $o['headers'] ?? [];

    return array_replace(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $auth, $extra);
}

/**
 * Header map → Symfony server vars so each request carries exactly these
 * headers (no cross-call accumulation).
 *
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function dsrv(array $headers): array
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

describe('POST /api/v1/devices/incoming-sms (full HMAC)', function () {
    it('confirms the single matching pending payment (201, matched)', function () {
        $body = smsBody(['raw_sms' => $this->depositText]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        $response = $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [],
            dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret)), $raw);

        $response->assertStatus(201)
            ->assertJsonPath('data.parse_status', 'parsed')
            ->assertJsonPath('data.match_status', 'matched')
            ->assertJsonPath('data.payment_id', $this->payment->id)
            ->assertJsonPath('data.duplicate', false);

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid);

        $sms = IncomingSms::query()->sole();
        expect($sms->parsed_amount)->toBe($this->payableAmount)
            ->and($sms->matched_payment_id)->toBe($this->payment->id);

        // §FR-9 stats advanced once.
        expect($this->device->fresh()->sms_count)->toBe(1)
            ->and($this->device->fresh()->last_ip)->not->toBeNull();

        // One device nonce consumed by the valid request.
        expect(DeviceNonce::query()->count())->toBe(1);
    });

    it('replays the original outcome on a duplicate message_id (200, duplicate:true)', function () {
        $body = smsBody(['raw_sms' => $this->depositText]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = fn (): array => dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret));

        $first = $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [], $headers(), $raw);
        $second = $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [], $headers(), $raw);

        $first->assertStatus(201);
        $second->assertStatus(200)
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.match_status', 'matched')
            ->assertJsonPath('data.payment_id', $this->payment->id);

        // Exactly one stored message; the matcher ran once; stats advanced once.
        expect(IncomingSms::query()->count())->toBe(1)
            ->and(Payment::query()->whereKey($this->payment->id)->where('status', 'paid')->exists())->toBeTrue()
            ->and($this->device->fresh()->sms_count)->toBe(1);
    });

    it('rejects a forged device signature and consumes no nonce or SMS', function () {
        $body = smsBody(['raw_sms' => $this->depositText]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret);
        $headers['X-Device-Signature'] = str_repeat('a', 64);

        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [], dsrv($headers), $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_device_signature');

        expect(IncomingSms::query()->count())->toBe(0)
            ->and(DeviceNonce::query()->count())->toBe(0);
    });

    it('rejects an unknown or revoked device key without touching business state', function () {
        $body = smsBody(['raw_sms' => $this->depositText]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        $unknown = dsrv(dSign('/api/v1/devices/incoming-sms', $raw, 'dk_unknown', $this->secret));
        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [], $unknown, $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_device_key');

        $this->device->forceFill(['revoked_at' => now()])->save();
        $revoked = dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret));
        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [], $revoked, $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_device_key');

        expect(IncomingSms::query()->count())->toBe(0)
            ->and($this->device->fresh()->sms_count)->toBe(0);
    });

    it('rejects a replayed device nonce', function () {
        $body = smsBody(['raw_sms' => $this->depositText]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        // Distinct message ids so only the NONCE forces the second rejection.
        $firstBody = $body;
        $secondBody = smsBody(['message_id' => 'other-'.Str::random(8), 'raw_sms' => $this->depositText]);
        unset($body);

        $ok = dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret, [
            'nonce' => 'fixed-device-nonce-1',
        ]));
        // Sign the second request over ITS OWN raw body but reuse the nonce.
        $raw2 = json_encode($secondBody, JSON_THROW_ON_ERROR);
        $replay = dsrv(dSign('/api/v1/devices/incoming-sms', $raw2, $this->deviceKey, $this->secret, [
            'nonce' => 'fixed-device-nonce-1',
        ]));

        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [], $ok, $raw)->assertStatus(201);
        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [], $replay, $raw2)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_device_signature');
    });

    it('validates the body: missing fields are 422 with field details', function () {
        foreach ([
            ['message_id' => ''],
            ['raw_sms' => ''],
            ['received_at' => 'not-a-date'],
        ] as $bad) {
            $body = smsBody($bad);
            $raw = json_encode($body, JSON_THROW_ON_ERROR);

            $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [],
                dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret)), $raw)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed');
        }

        expect(IncomingSms::query()->count())->toBe(0);
    });
});

describe('recognition outcomes via incoming-sms', function () {
    it('marks an ambiguous amount manual_review for ALL candidates and pays none', function () {
        // Force two pending payments onto the same payable amount: recreate our
        // payment's reservation twin so both rows carry identical amounts.
        $twin = $this->payment->replicate(['public_id']);
        $twin->public_id = 'PAY'.Str::ulid();
        $twin->save();

        $body = smsBody(['raw_sms' => $this->depositText]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [],
            dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret)), $raw)
            ->assertStatus(201)
            ->assertJsonPath('data.parse_status', 'parsed')
            ->assertJsonPath('data.match_status', 'ambiguous')
            ->assertJsonMissingPath('data.payment_id');

        // Fail-safe: NEITHER candidate was paid; both escalated.
        expect($this->payment->fresh()->status)->toBe(PaymentStatus::ManualReview)
            ->and($twin->fresh()->status)->toBe(PaymentStatus::ManualReview);
    });

    it('leaves a late deposit unmatched against an expired payment', function () {
        $this->payment->forceFill(['status' => PaymentStatus::Expired])->save();

        $body = smsBody(['raw_sms' => $this->depositText]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [],
            dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret)), $raw)
            ->assertStatus(201)
            ->assertJsonPath('data.match_status', MatchStatus::Unmatched->value);

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Expired);
    });

    it('ignores a withdrawal SMS (negative keyword) and never matches', function () {
        $debit = 'بانک سامان برداشت مبلغ '.$this->payableAmount.'ریال از حساب شما';
        $body = smsBody(['raw_sms' => $debit]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [],
            dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret)), $raw)
            ->assertStatus(201)
            ->assertJsonPath('data.parse_status', ParseStatus::Ignored->value)
            ->assertJsonPath('data.error', 'negative_transaction');

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Pending);
    });

    it('records parser_not_configured when the card has no active parser', function () {
        $this->parser->forceFill(['is_active' => false])->save();

        $body = smsBody(['raw_sms' => $this->depositText]);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [],
            dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret)), $raw)
            ->assertStatus(201)
            ->assertJsonPath('data.parse_status', ParseStatus::Failed->value)
            ->assertJsonPath('data.error', SmsIngestionService::REASON_PARSER_NOT_CONFIGURED);

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and(IncomingSms::query()->sole()->parse_error)
            ->toBe(SmsIngestionService::REASON_PARSER_NOT_CONFIGURED);
    });

    it('ignores a mismatching sender when a sender pattern is configured', function () {
        $body = smsBody(['raw_sms' => $this->depositText, 'sender' => '+989121111111']);
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/devices/incoming-sms', [], [], [],
            dsrv(dSign('/api/v1/devices/incoming-sms', $raw, $this->deviceKey, $this->secret)), $raw)
            ->assertStatus(201)
            ->assertJsonPath('data.parse_status', ParseStatus::Ignored->value)
            ->assertJsonPath('data.error', 'sender_mismatch');

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Pending);
    });
});

describe('POST /api/v1/devices/shortcut-sms', function () {
    it('accepts header credentials and applies shortcut defaults (201)', function () {
        // The derived default sender "iOS Shortcut" must pass this parser's
        // sender gate, so relax the pattern for this scenario.
        $this->parser->forceFill(['sender_pattern' => null])->save();

        $raw = json_encode(['raw_sms' => $this->depositText], JSON_THROW_ON_ERROR);

        // Headers win over body; message_id/sender/received_at omitted entirely.
        $headers = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ['X-Device-Key' => $this->deviceKey, 'X-Device-Secret' => $this->secret],
        );
        $response = $this->call('POST', '/api/v1/devices/shortcut-sms', [], [], [], dsrv($headers), $raw);

        $response->assertStatus(201)
            ->assertJsonPath('data.parse_status', ParseStatus::Parsed->value)
            ->assertJsonPath('data.match_status', MatchStatus::Matched->value)
            ->assertJsonPath('data.payment_id', $this->payment->id);

        $sms = IncomingSms::query()->sole();
        expect($sms->message_id)->toStartWith('ios_')
            ->and($sms->sender)->toBe('iOS Shortcut');
    });

    it('accepts body-field credentials when headers are absent', function () {
        $raw = json_encode([
            'device_key' => $this->deviceKey,
            'device_secret' => $this->secret,
            'message_id' => 'short-1',
            'sender' => '98700077',
            'raw_sms' => $this->depositText,
        ], JSON_THROW_ON_ERROR);

        $response = $this->call('POST', '/api/v1/devices/shortcut-sms', [], [], [],
            dsrv(['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw);

        $response->assertStatus(201)->assertJsonPath('data.duplicate', false);
    });

    it('prefers header credentials when both are present', function () {
        $raw = json_encode([
            // Body presents WRONG credentials; correct ones arrive in headers.
            'device_key' => 'dk_wrong',
            'device_secret' => 'wrong-secret',
            'message_id' => 'short-precedence',
            'raw_sms' => $this->depositText,
        ], JSON_THROW_ON_ERROR);

        $headers = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ['X-Device-Key' => $this->deviceKey, 'X-Device-Secret' => $this->secret],
        );

        $this->call('POST', '/api/v1/devices/shortcut-sms', [], [], [], dsrv($headers), $raw)
            ->assertStatus(201);
    });

    it('rejects a wrong secret with invalid_device_signature', function () {
        $raw = json_encode([
            // Known key, WRONG secret → fingerprint mismatch (not a key error).
            'device_key' => $this->deviceKey,
            'device_secret' => 'totally-wrong-'.Str::random(8),
            'message_id' => 'short-bad',
            'raw_sms' => $this->depositText,
        ], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/devices/shortcut-sms', [], [], [],
            dsrv(['Content-Type' => 'application/json', 'Accept' => 'application/json']), $raw)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_device_signature');

        expect(IncomingSms::query()->count())->toBe(0);
    });

    it('dedupes identical shortcut content even with derived message ids', function () {
        // Derived message ids are a pure function of the body, so two identical
        // Shortcut firings must collapse to one stored message.
        $this->parser->forceFill(['sender_pattern' => null])->save();

        $raw = json_encode(['raw_sms' => $this->depositText], JSON_THROW_ON_ERROR);
        $headers = dsrv(array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            ['X-Device-Key' => $this->deviceKey, 'X-Device-Secret' => $this->secret],
        ));

        $first = $this->call('POST', '/api/v1/devices/shortcut-sms', [], [], [], $headers, $raw);
        $second = $this->call('POST', '/api/v1/devices/shortcut-sms', [], [], [], $headers, $raw);

        $first->assertStatus(201);
        $second->assertStatus(200)->assertJsonPath('data.duplicate', true);

        expect(IncomingSms::query()->count())->toBe(1);
    });
});
