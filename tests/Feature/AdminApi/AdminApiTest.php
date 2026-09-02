<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\SmsParser;
use CartBecart\CardPay\Services\Provisioning\GatewayProvisioner;
use CartBecart\CardPay\Tests\Support\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| §16 — the Admin JSON API
|--------------------------------------------------------------------------
|
| Every panel capability as JSON, authorized by the SAME `cardpay.access` Gate
| the panel uses, so a host can render the gateway's admin in its own theme.
| Available in both editions; in lite it is the only admin surface.
|
| The invariants worth pinning are the ones that would be expensive to discover
| later: authorization on every route, and the fact that nothing here leaks a
| secret except the deliberate one-time reveals.
|
*/

uses(RefreshDatabase::class);

function adminApi(string $path = ''): string
{
    return cardpay_admin_api_url($path);
}

beforeEach(function () {
    $this->admin = TestUser::factory()->create();
    $this->card = BankCard::factory()->create();
});

describe('authorization', function () {
    it('rejects guests on every route', function (string $method, string $path) {
        $this->json($method, adminApi($path))->assertUnauthorized();
    })->with([
        ['get', 'overview'],
        ['get', 'payments'],
        ['get', 'cards'],
        ['post', 'cards'],
        ['get', 'devices'],
        ['get', 'parsers'],
        ['get', 'sms'],
        ['get', 'webhooks'],
        ['get', 'reports'],
        ['get', 'gateway'],
    ]);

    it('rejects an authenticated user who fails the cardpay.access gate', function () {
        $this->actingAs(TestUser::factory()->create(['role' => 'customer']));

        $this->getJson(adminApi('cards'))->assertForbidden();
    });

    it('rejects an admin who has been deactivated mid-session', function () {
        $this->actingAs($this->admin);
        $this->admin->forceFill(['is_active' => false])->save();

        $this->getJson(adminApi('cards'))->assertForbidden();
    });
});

describe('bank cards', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('creates a card and stores only the last four in the clear', function () {
        $response = $this->postJson(adminApi('cards'), [
            'title' => 'Main',
            'bank_name' => 'Saman',
            'card_number' => '6219861012345678',
            'card_holder_name' => 'Sara Ahmadi',
        ])->assertCreated();

        $response->assertJsonPath('data.last_four', '5678');

        // The PAN must not appear in a create response or a listing.
        expect($response->getContent())->not->toContain('6219861012345678');

        $card = BankCard::query()->where('title', 'Main')->sole();
        expect($card->card_number_last_four)->toBe('5678')
            ->and($card->getRawOriginal('card_number_encrypted'))->not->toContain('6219861012345678');
    });

    it('omits the PAN from listings but serves it from the single-card endpoint', function () {
        $list = $this->getJson(adminApi('cards'))->assertOk();
        expect($list->json('data.0'))->not->toHaveKey('card_number');

        $this->getJson(adminApi('cards/'.$this->card->id))
            ->assertOk()
            ->assertJsonPath('data.card_number', (string) $this->card->card_number_encrypted);
    });

    it('keeps the stored PAN when an update omits the number', function () {
        $before = (string) $this->card->card_number_encrypted;

        $this->putJson(adminApi('cards/'.$this->card->id), [
            'title' => 'Renamed',
            'bank_name' => $this->card->bank_name,
            'card_holder_name' => $this->card->card_holder_name,
        ])->assertOk()->assertJsonPath('data.title', 'Renamed');

        expect((string) $this->card->fresh()->card_number_encrypted)->toBe($before);
    });

    it('soft-disables instead of deleting, so payment history keeps its card', function () {
        $this->deleteJson(adminApi('cards/'.$this->card->id))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect(BankCard::query()->whereKey($this->card->id)->exists())->toBeTrue();

        $this->postJson(adminApi('cards/'.$this->card->id.'/activate'))
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    });

    it('rejects an invalid card number', function () {
        $this->postJson(adminApi('cards'), [
            'title' => 'Bad',
            'bank_name' => 'Saman',
            'card_number' => 'not-digits',
            'card_holder_name' => 'X',
        ])->assertStatus(422);
    });
});

describe('devices', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('reveals the secret exactly once, at creation', function () {
        $created = $this->postJson(adminApi('devices'), [
            'name' => 'Relay phone',
            'platform' => 'android',
            'bank_card_id' => $this->card->id,
        ])->assertCreated();

        $secret = $created->json('data.secret');
        expect($secret)->toBeString()->not->toBeEmpty();

        // Never again — not in the listing, not in the detail endpoint.
        $device = Device::query()->latest('id')->sole();

        expect($this->getJson(adminApi('devices'))->getContent())->not->toContain($secret);
        expect($this->getJson(adminApi('devices/'.$device->id))->getContent())->not->toContain($secret);
    });

    it('rotates to a new secret', function () {
        $device = Device::query()->create([
            'name' => 'Relay', 'platform' => 'android', 'bank_card_id' => $this->card->id,
            'device_key' => 'dk_old', 'device_secret_encrypted' => 'old-secret',
            'secret_fingerprint' => hash('sha256', 'old-secret'), 'is_active' => true,
        ]);

        $rotated = $this->postJson(adminApi('devices/'.$device->id.'/rotate'))->assertOk();

        expect($rotated->json('data.secret'))->not->toBe('old-secret')
            ->and($rotated->json('data.device_key'))->not->toBe('dk_old');
    });

    it('revokes permanently', function () {
        $device = Device::query()->create([
            'name' => 'Relay', 'platform' => 'android', 'bank_card_id' => $this->card->id,
            'device_key' => 'dk_x', 'device_secret_encrypted' => 's',
            'secret_fingerprint' => hash('sha256', 's'), 'is_active' => true,
        ]);

        $this->postJson(adminApi('devices/'.$device->id.'/revoke'))
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.is_usable', false);

        expect($device->fresh()->revoked_at)->not->toBeNull();
    });
});

describe('sms parsers', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('accepts keyword lists as a JSON array', function () {
        $this->postJson(adminApi('parsers'), [
            'name' => 'Saman',
            'bank_name' => 'Saman',
            'amount_regex' => '/(\d[\d,]*)/',
            'positive_keywords' => ['deposit', 'credit'],
        ])->assertCreated()->assertJsonPath('data.positive_keywords', ['deposit', 'credit']);
    });

    it('also accepts the panel’s comma-separated form', function () {
        $this->postJson(adminApi('parsers'), [
            'name' => 'Mellat',
            'bank_name' => 'Mellat',
            'amount_regex' => '/(\d+)/',
            'positive_keywords' => 'deposit, credit',
        ])->assertCreated()->assertJsonPath('data.positive_keywords', ['deposit', 'credit']);
    });

    it('dry-runs a parser against sample text without persisting anything', function () {
        $parser = SmsParser::query()->create([
            'name' => 'T', 'bank_name' => 'Saman',
            'amount_regex' => '/مبلغ\s*([\d,]+)/u', 'is_active' => true,
        ]);

        $this->postJson(adminApi('parsers/'.$parser->id.'/test'), [
            'text' => 'واریز مبلغ 250,000 ریال',
        ])->assertOk()->assertJsonPath('data.amount', 250000);

        expect(IncomingSms::query()->count())->toBe(0);
    });

    it('reports a sender mismatch the way ingestion would', function () {
        $parser = SmsParser::query()->create([
            'name' => 'T', 'bank_name' => 'Saman', 'sender_pattern' => '/^BANK$/',
            'amount_regex' => '/(\d+)/', 'is_active' => true,
        ]);

        $this->postJson(adminApi('parsers/'.$parser->id.'/test'), [
            'text' => '1000', 'sender' => 'SPAM',
        ])->assertOk()->assertJsonPath('data.error', 'sender_mismatch');
    });
});

describe('payments', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('lists and filters by status', function () {
        Payment::factory()->forCard($this->card)->count(2)->create();
        Payment::factory()->forCard($this->card)->paid()->create();

        $this->getJson(adminApi('payments'))->assertOk()->assertJsonCount(3, 'data');

        $this->getJson(adminApi('payments?status=paid'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'paid');
    });

    it('finds a payment by the host’s own order id', function () {
        Payment::factory()->forCard($this->card)->create(['external_order_id' => 'ORD-77']);
        Payment::factory()->forCard($this->card)->create(['external_order_id' => 'ORD-88']);

        $this->getJson(adminApi('payments?external_order_id=ORD-77'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_order_id', 'ORD-77');
    });

    it('returns the detail view with evidence and webhook history', function () {
        $payment = Payment::factory()->forCard($this->card)->create();

        $this->getJson(adminApi('payments/'.$payment->public_id))
            ->assertOk()
            ->assertJsonPath('data.payment_id', $payment->public_id)
            ->assertJsonStructure(['data' => ['bank_card', 'sms_evidence', 'reviews', 'webhook_events']]);
    });

    it('404s an unknown payment', function () {
        $this->getJson(adminApi('payments/PAYnope'))->assertNotFound();
    });

    it('clamps an absurd page size', function () {
        Payment::factory()->forCard($this->card)->count(3)->create();

        $this->getJson(adminApi('payments?per_page=100000'))
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    });
});

describe('review queue', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('approves a review and settles the payment through the state machine', function () {
        $payment = Payment::factory()->forCard($this->card)->create();
        $review = ManualReviewRequest::query()->create([
            'payment_id' => $payment->id, 'status' => 'pending', 'reported_amount' => $payment->payable_amount,
        ]);

        $this->postJson(adminApi('reviews/'.$review->id.'/approve'), ['note' => 'Receipt checked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect($payment->fresh()->status->value)->toBe('paid');
    });

    it('rejects a review without settling', function () {
        // Rejection is only legal from manual_review (§9.2): a plain pending
        // payment expires or cancels, it is never "rejected".
        $payment = Payment::factory()->forCard($this->card)->manualReview()->create();
        $review = ManualReviewRequest::query()->create([
            'payment_id' => $payment->id, 'status' => 'pending',
        ]);

        $this->postJson(adminApi('reviews/'.$review->id.'/reject'))
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        expect($payment->fresh()->status->value)->toBe('rejected');
    });

    it('refuses to reject a plain pending payment, since that transition is not in the map', function () {
        $payment = Payment::factory()->forCard($this->card)->create();
        $review = ManualReviewRequest::query()->create([
            'payment_id' => $payment->id, 'status' => 'pending',
        ]);

        $this->postJson(adminApi('reviews/'.$review->id.'/reject'))
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        expect($payment->fresh()->status->value)->toBe('pending');
    });

    it('returns a catalog error rather than a 500 when the review was already decided', function () {
        $payment = Payment::factory()->forCard($this->card)->create();
        $review = ManualReviewRequest::query()->create([
            'payment_id' => $payment->id, 'status' => 'approved',
        ]);

        $this->postJson(adminApi('reviews/'.$review->id.'/approve'))
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    });

    it('defaults the queue to pending items', function () {
        $payment = Payment::factory()->forCard($this->card)->create();
        ManualReviewRequest::query()->create(['payment_id' => $payment->id, 'status' => 'pending']);
        ManualReviewRequest::query()->create(['payment_id' => $payment->id, 'status' => 'rejected']);

        $this->getJson(adminApi('reviews'))->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('gateway settings', function () {
    beforeEach(function () {
        $this->actingAs($this->admin);
        app(GatewayProvisioner::class)->provision();
    });

    it('exposes the single application without ever revealing a secret', function () {
        $response = $this->getJson(adminApi('gateway'))->assertOk();

        $response->assertJsonPath('data.slug', 'store')
            ->assertJsonStructure(['data' => ['public_key', 'webhook_url', 'api_keys']]);

        expect($response->json('data.api_keys.0'))->not->toHaveKey('secret');
    });

    it('updates the webhook target and the return-url allow-list', function () {
        $this->putJson(adminApi('gateway'), [
            'webhook_url' => 'https://shop.test/hooks/cardpay',
            'allowed_domains' => ['Shop.test', 'cdn.shop.test'],
        ])->assertOk()
            ->assertJsonPath('data.webhook_url', 'https://shop.test/hooks/cardpay')
            ->assertJsonPath('data.allowed_domains', ['shop.test', 'cdn.shop.test']);
    });

    it('refuses a non-http webhook url', function () {
        $this->putJson(adminApi('gateway'), ['webhook_url' => 'javascript:alert(1)'])
            ->assertStatus(422);
    });

    it('rotates the API key, revoking the old one and revealing the new secret once', function () {
        $before = $this->getJson(adminApi('gateway'))->json('data.api_keys.0.public_key');

        $rotated = $this->postJson(adminApi('gateway/rotate-api-key'))->assertOk();

        expect($rotated->json('data.credentials.secret'))->toBeString()->not->toBeEmpty()
            ->and($rotated->json('data.credentials.public_key'))->not->toBe($before);

        $keys = collect($rotated->json('data.gateway.api_keys'))->keyBy('public_key');
        expect($keys[$before]['is_active'])->toBeFalse();
    });
});

describe('sms log, webhooks and reports', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('filters the sms log by match status', function () {
        $device = Device::query()->create([
            'name' => 'R', 'platform' => 'android', 'bank_card_id' => $this->card->id,
            'device_key' => 'dk_1', 'device_secret_encrypted' => 's',
            'secret_fingerprint' => hash('sha256', 's'), 'is_active' => true,
        ]);

        foreach (['unmatched', 'matched'] as $i => $status) {
            IncomingSms::query()->create([
                'device_id' => $device->id, 'bank_card_id' => $this->card->id,
                'message_id' => 'm'.$i, 'raw_sms' => 'text', 'received_at' => now(),
                'server_received_at' => now(), 'parse_status' => 'parsed', 'match_status' => $status,
            ]);
        }

        $this->getJson(adminApi('sms?match_status=unmatched'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.match_status', 'unmatched');
    });

    it('serves webhook events and the deliveries view', function () {
        $this->getJson(adminApi('webhooks'))->assertOk();
        $this->getJson(adminApi('webhooks/deliveries'))->assertOk();
    });

    it('summarises payments over a date window', function () {
        Payment::factory()->forCard($this->card)->paid()->create();

        $response = $this->getJson(adminApi('reports'))->assertOk();

        $paid = collect($response->json('data.rows'))->firstWhere('status', 'paid');
        expect($paid['count'])->toBe(1);
    });

    it('streams a CSV export', function () {
        Payment::factory()->forCard($this->card)->create();

        $this->get(adminApi('reports/csv'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    });
});
