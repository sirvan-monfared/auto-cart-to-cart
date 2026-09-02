<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §FR-16 admin panel pages
|--------------------------------------------------------------------------
|
| Every page renders for an ACTIVE ADMIN; guests and non-admins never see
| them (the `admin` gate redirects). The webhook retry action re-queues only
| failed/exhausted deliveries.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

    $this->admin = User::factory()->create();
    $this->card = BankCard::factory()->create();
    $this->merchant = Application::factory()->create(['default_bank_card_id' => $this->card->id]);

    $this->payment = Payment::query()->create([
        'public_id' => 'PAY'.Str::ulid(),
        'application_id' => $this->merchant->id,
        'bank_card_id' => $this->card->id,
        'driver' => 'card_transfer',
        'original_amount' => 100_000,
        'token' => 417,
        'payable_amount' => 100_417,
        'currency' => 'IRR',
        'status' => 'pending',
        'expires_at' => now()->addMinutes(30),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renders all panel pages for an active admin', function () {
    $this->actingAs($this->admin);

    $this->get(cardpay_test_url())->assertOk()->assertSee(__('CardPay Admin'));
    $this->get(cardpay_test_url('payments'))->assertOk()->assertSee($this->payment->public_id);
    $this->get(cardpay_test_url('payments/').$this->payment->public_id)->assertOk()->assertSee(__('Payment').' '.$this->payment->public_id);
    $this->get(cardpay_test_url('reviews'))->assertOk()->assertSee(__('Manual review queue'));
    $this->get(cardpay_test_url('sms-log'))->assertOk();
    $this->get(cardpay_test_url('sms-log?match=unmatched'))->assertOk();
    $this->get(cardpay_test_url('webhooks'))->assertOk();
    $this->get(cardpay_test_url('audit'))->assertOk();
});

it('shows the review in the queue and hides decided ones from pending list', function () {
    $review = ManualReviewRequest::query()->create([
        'payment_id' => $this->payment->id,
        'reported_amount' => 100_417,
        'status' => 'pending',
    ]);

    // Pending: appears with its decision forms.
    $pending = $this->actingAs($this->admin)
        ->get(cardpay_test_url('reviews'))
        ->assertOk();
    expect(str_contains($pending->getContent(), 'admin.reviews.approve') || str_contains($pending->getContent(), cardpay_test_url('reviews/').$review->id.'/approve'))->toBeTrue();

    // Decide it: it leaves the PENDING set (the page's decided list may show
    // it for reference, but the approve/reject form is gone).
    ManualReviewRequest::query()->whereKey($review->id)->update(['status' => 'rejected']);

    $after = $this->actingAs($this->admin)->get(cardpay_test_url('reviews'))->assertOk();
    expect(str_contains($after->getContent(), cardpay_test_url('reviews/').$review->id.'/approve'))->toBeFalse();
});

it('shows unmatched SMS in the unmatched explorer', function () {
    IncomingSms::query()->create([
        'device_id' => 1,
        'bank_card_id' => $this->card->id,
        'message_id' => Str::random(12),
        'raw_sms' => 'whatever',
        'received_at' => now(),
        'server_received_at' => now(),
        'parse_status' => 'parsed',
        'parsed_amount' => 999,
        'match_status' => 'unmatched',
    ]);

    $this->actingAs($this->admin)
        ->get(cardpay_test_url('sms-log?match=unmatched'))
        ->assertOk()
        ->assertSee('unmatched');
});

it('blocks panel pages for guests with a redirect to login', function () {
    foreach ([cardpay_test_url(), cardpay_test_url('payments'), cardpay_test_url('reviews'), cardpay_test_url('webhooks'), cardpay_test_url('audit')] as $path) {
        $this->get($path)->assertRedirect(route('login'));
    }
});
