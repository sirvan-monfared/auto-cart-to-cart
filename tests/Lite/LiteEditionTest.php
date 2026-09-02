<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Support\Edition;
use CartBecart\CardPay\Tests\Support\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| §16 — the lite distribution
|--------------------------------------------------------------------------
|
| Lite is the single-shop, API-only build: no bundled panel, no setup wizard,
| no audit or settings tables. What it must NOT lose is the gateway itself —
| checkout, the merchant API, device SMS ingest, and the matching engine all
| stay, because removing them would make it a worse product rather than a
| smaller one.
|
| These tests pin both halves of that contract.
|
*/

uses(RefreshDatabase::class);

it('runs in the lite edition', function () {
    expect(Edition::current())->toBe('lite');
});

describe('the panel is gone', function () {
    it('registers no panel routes', function () {
        $this->get(cardpay_url())->assertNotFound();
        $this->get(cardpay_url('payments'))->assertNotFound();
        $this->get(cardpay_url('cards'))->assertNotFound();
        $this->get(cardpay_url('settings'))->assertNotFound();
    });

    it('registers no setup wizard', function () {
        $this->get('/'.cardpay_path().'/setup')->assertNotFound();
    });

    it('does not reach the panel even for an authenticated admin — the routes do not exist at all', function () {
        $this->actingAs(TestUser::factory()->create());

        $this->get(cardpay_url())->assertNotFound();
    });
});

describe('the panel-only tables are never created', function () {
    it('omits cp_audit_logs and cp_settings from the schema', function () {
        expect(Schema::hasTable('cp_audit_logs'))->toBeFalse()
            ->and(Schema::hasTable('cp_settings'))->toBeFalse();
    });

    it('still creates every table the money path needs', function (string $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    })->with([
        'cp_payments',
        'cp_payment_token_reservations',
        'cp_bank_cards',
        'cp_devices',
        'cp_incoming_sms',
        'cp_sms_parsers',
        'cp_payment_matches',
        'cp_manual_review_requests',
        'cp_applications',
        'cp_application_api_keys',
        'cp_webhook_events',
        'cp_webhook_deliveries',
        'cp_idempotency_keys',
        'cp_api_nonces',
        'cp_device_nonces',
        'cp_rate_limits',
    ]);

    it('resolves settings from config instead of the missing table', function () {
        config()->set('cardpay.settings.payment_title', 'Pay by card transfer');

        expect(Setting::get('payment_title'))->toBe('Pay by card transfer')
            ->and(Setting::get('nonexistent', 'fallback'))->toBe('fallback');
    });

    it('accepts a settings write as a silent no-op rather than exploding on the absent table', function () {
        Setting::put('payment_title', 'ignored');

        expect(Schema::hasTable('cp_settings'))->toBeFalse();
    });

    it('turns audit writes into no-ops so business code never has to branch', function () {
        (new AuditLogger)->log('card.created', 'admin', 1, 'bank_card', '1');
    })->throwsNoExceptions();
});

describe('the gateway itself is intact', function () {
    it('serves the hosted checkout page', function () {
        $payment = Payment::factory()->forCard(BankCard::factory()->create())->create();

        $this->get('/p/'.$payment->public_id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    });

    it('serves public status polling', function () {
        $payment = Payment::factory()->forCard(BankCard::factory()->create())->create();

        $this->getJson('/api/v1/public/payments/'.$payment->public_id.'/status')->assertOk();
    });

    it('still guards the merchant API with HMAC rather than leaving it open', function () {
        $this->postJson('/api/v1/payments', ['amount' => 1000])->assertUnauthorized();
    });

    it('still exposes the device SMS relay', function () {
        // Unauthenticated, so it must be REJECTED — but it must exist.
        $this->postJson('/api/v1/devices/incoming-sms', [])->assertUnauthorized();
    });
});

describe('the admin API replaces the panel', function () {
    it('rejects a guest', function () {
        $this->getJson(cardpay_admin_api_url('overview'))->assertUnauthorized();
    });

    it('rejects an authenticated non-admin', function () {
        $this->actingAs(TestUser::factory()->create(['role' => 'customer']));

        $this->getJson(cardpay_admin_api_url('overview'))->assertForbidden();
    });

    it('serves the dashboard counters to an admin', function () {
        $this->actingAs(TestUser::factory()->create());

        $this->getJson(cardpay_admin_api_url('overview'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['payments', 'paid_today', 'pending_reviews', 'unmatched_sms']]);
    });

    it('advertises the edition and feature map so a host admin can render its own menu', function () {
        $this->actingAs(TestUser::factory()->create());

        $this->getJson(cardpay_admin_api_url('features'))
            ->assertOk()
            ->assertJsonPath('data.edition', 'lite')
            ->assertJsonPath('data.features.panel', false)
            ->assertJsonPath('data.features.admin_api', true)
            ->assertJsonPath('data.features.checkout', true);
    });
});
