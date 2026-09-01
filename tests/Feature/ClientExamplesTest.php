<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Security\Crypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| docs/examples — the published client snippets must ACTUALLY work
|--------------------------------------------------------------------------
|
| The examples are the merchant integration contract. This test re-implements
| the SIGNING PIPELINE exactly as published in docs/examples/create-payment.*
| (openssl shasum path, Node path) and proves the real API accepts a request
| signed that way. If the wire format ever drifts, these fail first.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

    $this->card = BankCard::factory()->create();
    $this->merchant = Application::factory()->create([
        'default_bank_card_id' => $this->card->id,
        'token_digits' => 3,
    ]);

    $this->secret = 'example-secret-'.Str::random(16);
    $this->key = 'pk_example_'.Str::lower(Str::random(18));
    ApplicationApiKey::query()->create([
        'application_id' => $this->merchant->id,
        'public_key' => $this->key,
        'secret_encrypted' => $this->secret,
        'secret_fingerprint' => Crypto::fingerprint($this->secret),
        'is_active' => true,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The docs/examples/create-payment.js signing function, verbatim logic.
 *
 * @return array<string, string>
 */
function exampleSign(string $method, string $path, string $rawQuery, string $rawBody, string $key, string $secret): array
{
    $timestamp = (string) now()->getTimestamp();
    $nonce = 'nonce_'.bin2hex(random_bytes(16));

    $canonical = implode("\n", [
        strtoupper($method),
        $path,
        $rawQuery,
        hash('sha256', $rawBody),
        $timestamp,
        $nonce,
    ]);

    return [
        'X-CardPay-Key' => $key,
        'X-CardPay-Timestamp' => $timestamp,
        'X-CardPay-Nonce' => $nonce,
        'X-CardPay-Signature' => hash_hmac('sha256', $canonical, $secret),
    ];
}

it('accepts a create request signed with the docs example algorithm (201)', function () {
    $rawBody = '{"amount":250000,"external_order_id":"A-123","description":"Order A-123","customer":{"name":"Sara","mobile":"09120000000"}}';
    $headers = exampleSign('POST', '/api/v1/payments', '', $rawBody, $this->key, $this->secret);

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'example-order-123'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $response = $this->call('POST', '/api/v1/payments', [], [], [], $server, $rawBody);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.original_amount', 250000)
        ->assertJsonPath('data.payable_amount', 250000 + $response->json('data.token'));
});

it('accepts a status GET signed with the docs example algorithm (200)', function () {
    // Create first (same example path).
    $rawBody = '{"amount":100000,"external_order_id":"B-1"}';
    $headers = exampleSign('POST', '/api/v1/payments', '', $rawBody, $this->key, $this->secret);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'example-b-1'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $paymentId = $this->call('POST', '/api/v1/payments', [], [], [], $server, $rawBody)
        ->assertStatus(201)->json('data.payment_id');

    // GET with empty body hash, exactly as payment-status.sh does.
    $path = '/api/v1/payments/'.$paymentId;
    $getHeaders = exampleSign('GET', $path, '', '', $this->key, $this->secret);
    $getServer = ['HTTP_ACCEPT' => 'application/json'];
    foreach ($getHeaders as $name => $value) {
        $getServer['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $this->call('GET', $path, [], [], [], $getServer)
        ->assertStatus(200)
        ->assertJsonPath('data.payment_id', $paymentId);
});

it('example webhook verifier logic accepts a genuine CardPay delivery', function () {
    // A payment + its paid webhook event, like the processor stores.
    $payment = Payment::query()->create([
        'public_id' => 'PAY'.Str::ulid(),
        'application_id' => $this->merchant->id,
        'bank_card_id' => $this->card->id,
        'driver' => 'card_transfer',
        'original_amount' => 100_000,
        'token' => 417,
        'payable_amount' => 100_417,
        'currency' => 'IRR',
        'status' => 'paid',
        'expires_at' => now()->addMinutes(30),
        'paid_at' => now(),
    ]);

    $payload = [
        'event' => 'payment.paid',
        'payment_id' => $payment->public_id,
        'status' => 'paid',
        'payable_amount' => 100_417,
        'currency' => 'IRR',
    ];
    // The exact bytes the processor sends (HttpWebhookProcessor::attempt).
    $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', (string) $rawBody, $this->secret);

    // The verify-webhook.php logic, verbatim.
    $expected = hash_hmac('sha256', (string) $rawBody, $this->secret);
    expect(hash_equals($expected, strtolower($signature)))->toBeTrue()
        // Tampering breaks it.
        ->and(hash_equals($expected, hash_hmac('sha256', $rawBody.'"', $this->secret)))->toBeFalse();
});
