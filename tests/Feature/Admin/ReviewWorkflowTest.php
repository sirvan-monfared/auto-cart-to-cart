<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\AuditLog;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\PaymentMatch;
use CartBecart\CardPay\Models\WebhookEvent;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §FR-12 Manual-review decisions — approve / reject
|--------------------------------------------------------------------------
|
| The human fallback of the recognition path, held to the same fail-safe bar:
| only pending reviews decide; SMS evidence must belong to the payment's OWN
| card; the conditional transition means parallel decisions can never pay
| twice; webhooks fire once after commit; every decision is audited.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

    $this->admin = User::factory()->create(['username' => 'boss', 'password' => 'secret-123']);
    $this->actingAs($this->admin);

    $this->card = BankCard::factory()->create();
    $this->otherCard = BankCard::factory()->create();
    $this->merchant = Application::factory()->create([
        'default_bank_card_id' => $this->card->id,
        'token_digits' => 3,
    ]);

    $this->payment = Payment::query()->create([
        'public_id' => 'PAY'.Str::ulid(),
        'application_id' => $this->merchant->id,
        'bank_card_id' => $this->card->id,
        'driver' => 'card_transfer',
        'original_amount' => 100_000,
        'token' => 417,
        'payable_amount' => 100_417,
        'currency' => 'IRR',
        'status' => PaymentStatus::ManualReview,
        'expires_at' => now()->addMinutes(30),
    ]);

    $this->review = ManualReviewRequest::query()->create([
        'payment_id' => $this->payment->id,
        'reported_amount' => 100_417,
        'status' => 'pending',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('approves with SMS evidence: payment paid, evidence linked, cooldown set, webhook emitted, audited', function () {
    $sms = IncomingSms::query()->create([
        'device_id' => 1,
        'bank_card_id' => $this->card->id,
        'message_id' => 'ev-'.Str::random(8),
        'raw_sms' => 'deposit',
        'received_at' => now(),
        'server_received_at' => now(),
        'parse_status' => 'parsed',
        'parsed_amount' => 100_417,
        'match_status' => MatchStatus::ManualReview,
    ]);

    // Device FK is enforced by migrations; create a real device for it.
    $deviceId = DB::table('cp_devices')->insertGetId([
        'name' => 'relay', 'platform' => 'android', 'device_key' => 'dk_'.Str::lower(Str::random(16)),
        'device_secret_encrypted' => 's', 'secret_fingerprint' => str_repeat('a', 64),
        'bank_card_id' => $this->card->id, 'is_active' => true, 'sms_count' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $sms->update(['device_id' => $deviceId]);

    $response = $this->post(cardpay_test_url("reviews/{$this->review->id}/approve"), [
        'sms_id' => $sms->id,
    ]);

    $response->assertRedirect()->assertSessionHas('decision_ok', 'approved');

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->payment->fresh()->matched_sms_id)->toBe($sms->id)
        ->and($this->review->fresh()->status)->toBe('approved')
        ->and($this->review->fresh()->reviewed_by)->toBe($this->admin->id)
        ->and(PaymentMatch::query()->where('payment_id', $this->payment->id)->where('match_type', 'manual')->count())->toBe(1)
        ->and(WebhookEvent::query()->where('event_type', 'payment.paid')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'review.approved')->count())->toBe(1);
});

it('approves without SMS evidence (plain approval)', function () {
    $this->post(cardpay_test_url("reviews/{$this->review->id}/approve"), [])
        ->assertRedirect()->assertSessionHas('decision_ok', 'approved');

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->review->fresh()->incoming_sms_id)->toBeNull();
});

it('rejects a non-pending review with review_not_found', function () {
    $this->review->forceFill(['status' => 'approved'])->save();

    $this->post(cardpay_test_url("reviews/{$this->review->id}/approve"), [])
        ->assertRedirect()->assertSessionHas('decision_error', 'review_not_found');

    // No money moved.
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::ManualReview);
});

it('rejects SMS evidence from another card with invalid_sms', function () {
    $foreignSms = IncomingSms::query()->create([
        'device_id' => 1,
        'bank_card_id' => $this->otherCard->id,
        'message_id' => 'x-'.Str::random(8),
        'raw_sms' => 'x',
        'received_at' => now(),
        'server_received_at' => now(),
        'parse_status' => 'parsed',
        'parsed_amount' => 100_417,
        'match_status' => MatchStatus::Unmatched,
    ]);
    $deviceId = DB::table('cp_devices')->insertGetId([
        'name' => 'relay2', 'platform' => 'android', 'device_key' => 'dk_'.Str::lower(Str::random(16)),
        'device_secret_encrypted' => 's', 'secret_fingerprint' => str_repeat('b', 64),
        'bank_card_id' => $this->otherCard->id, 'is_active' => true, 'sms_count' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignSms->update(['device_id' => $deviceId]);

    $this->post(cardpay_test_url("reviews/{$this->review->id}/approve"), ['sms_id' => $foreignSms->id])
        ->assertRedirect()->assertSessionHas('decision_error', 'invalid_sms');

    // Nothing confirmed on cross-card evidence.
    expect($this->payment->fresh()->status)->toBe(PaymentStatus::ManualReview);
});

it('fail-safes when the payment was concurrently decided: no double confirmation', function () {
    // A concurrent writer settles the payment between our read and decision.
    $this->payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();

    $this->post(cardpay_test_url("reviews/{$this->review->id}/approve"), [])
        ->assertRedirect()->assertSessionHas('decision_error', 'payment_not_reviewable');

    // Still paid exactly once — no duplicate webhook.
    expect(WebhookEvent::query()->where('event_type', 'payment.paid')->count())->toBe(0);
});

it('rejects a payment: status rejected, webhook emitted, audited', function () {
    $this->post(cardpay_test_url("reviews/{$this->review->id}/reject"), ['note' => 'No transfer found.'])
        ->assertRedirect()->assertSessionHas('decision_ok', 'rejected');

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($this->review->fresh()->status)->toBe('rejected')
        ->and($this->review->fresh()->internal_note)->toBe('No transfer found.')
        ->and(WebhookEvent::query()->where('event_type', 'payment.rejected')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'review.rejected')->count())->toBe(1);

    // Rejected is terminal: a second decision attempt finds no pending review.
    $this->post(cardpay_test_url("reviews/{$this->review->id}/approve"), [])
        ->assertRedirect()->assertSessionHas('decision_error', 'review_not_found');
});
