<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\AuditLog;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\SmsParser;
use CartBecart\CardPay\Services\Security\Crypto;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| §FR-4/§FR-6/§FR-3/§FR-5 admin CRUD
|--------------------------------------------------------------------------
|
| The security-critical properties: card numbers/IBANs and device/app secrets
| are stored ENCRYPTED (never plaintext in the DB); secrets are revealed once
| via session flash; rotation revokes the old credential; revoked devices can
    | never relay again; the parser live-test runs without persisting.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));
    $this->actingAs(User::factory()->create());
    $this->parser = SmsParser::query()->create([
        'name' => 'Saman Bank deposit',
        'bank_name' => 'Saman',
        'amount_regex' => '/واریز\s+مبلغ\s+(?<amount>[0-9۰-۹,٬ ]+)\s*ریال/u',
        'positive_keywords' => ['واریز'],
        'negative_keywords' => ['برداشت'],
        'is_active' => true,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('bank cards (§FR-4)', function () {
    it('creates a card storing the number ENCRYPTED with last-four in clear', function () {
        $this->post(cardpay_test_url('cards'), [
            'title' => 'Main settlement card',
            'bank_name' => 'Saman',
            'card_number' => '6219861012345678',
            'card_holder_name' => 'Ali Rezaei',
            'iban' => 'IR820540102680020817909002',
            'sms_parser_id' => $this->parser->id,
            'is_active' => '1',
        ])->assertRedirect();

        $card = BankCard::query()->where('title', 'Main settlement card')->sole();

        // Display field is clear; full number is ciphertext (not the raw digits).
        expect($card->card_number_last_four)->toBe('5678')
            ->and($card->card_number_encrypted)->toBe('6219861012345678') // cast decrypts on read
            ->and($card->getRawOriginal('card_number_encrypted'))->not->toContain('62198610');

        expect(AuditLog::query()->where('action', 'card.created')->count())->toBe(1);
    });

    it('deactivates instead of deleting so history stays intact', function () {
        $card = BankCard::factory()->create();

        $this->delete(cardpay_test_url('cards/').$card->id)->assertRedirect();

        expect(BankCard::query()->whereKey($card->id)->exists())->toBeTrue()
            ->and($card->fresh()->is_active)->toBeFalse()
            ->and(AuditLog::query()->where('action', 'card.deactivated')->count())->toBe(1);
    });

    it('re-activates a deactivated card and the audit entry is idempotent', function () {
        $card = BankCard::factory()->inactive()->create();

        $this->post(cardpay_test_url('cards/').$card->id.'/activate')->assertRedirect();

        expect($card->fresh()->is_active)->toBeTrue()
            ->and(AuditLog::query()->where('action', 'card.activated')->count())->toBe(1);

        // Activating again is a no-op — no duplicate audit entry.
        $this->post(cardpay_test_url('cards/').$card->id.'/activate')->assertRedirect();
        expect(AuditLog::query()->where('action', 'card.activated')->count())->toBe(1);
    });

    it('renders the edit modal and activate toggle on the cards page', function () {
        BankCard::factory()->create();
        BankCard::factory()->inactive()->create();

        $this->get(cardpay_test_url('cards'))
            ->assertOk()
            ->assertSee(__('Edit'))
            ->assertSee(__('Edit card'))
            ->assertSee(__('Activate'))
            ->assertSee(__('Deactivate'))
            ->assertSee('edit-card-');
    });

    it('updates metadata without touching the encrypted number when left blank', function () {
        $card = BankCard::factory()->create([
            'title' => 'Old title',
            'card_number_encrypted' => '6219861012345678',
            'card_number_last_four' => '5678',
        ]);
        $rawBefore = $card->getRawOriginal('card_number_encrypted');

        $this->put(cardpay_test_url('cards/').$card->id, [
            'title' => 'Renamed card',
            'bank_name' => 'Mellat',
            'card_holder_name' => 'Ali Rezaei',
            'sms_parser_id' => $this->parser->id,
        ])->assertRedirect();

        $card->refresh();

        expect($card->title)->toBe('Renamed card')
            ->and($card->bank_name)->toBe('Mellat')
            ->and($card->card_number_last_four)->toBe('5678')
            ->and($card->card_number_encrypted)->toBe('6219861012345678') // cast decrypts on read
            ->and($card->getRawOriginal('card_number_encrypted'))->toBe($rawBefore)
            ->and($card->sms_parser_id)->toBe($this->parser->id)
            ->and(AuditLog::query()->where('action', 'card.updated')->count())->toBe(1);
    });

    it('re-encrypts the number and IBAN when new values are supplied', function () {
        $card = BankCard::factory()->create([
            'card_number_encrypted' => '6219861012345678',
            'card_number_last_four' => '5678',
        ]);

        $this->put(cardpay_test_url('cards/').$card->id, [
            'title' => $card->title,
            'bank_name' => $card->bank_name,
            'card_number' => '6037991111111111',
            'card_holder_name' => $card->card_holder_name,
            'iban' => 'IR820540102680020817909002',
        ])->assertRedirect();

        $card->refresh();

        expect($card->card_number_last_four)->toBe('1111')
            ->and($card->card_number_encrypted)->toBe('6037991111111111')
            ->and($card->getRawOriginal('card_number_encrypted'))->not->toContain('60379911')
            ->and($card->iban_encrypted)->toBe('IR820540102680020817909002'); // cast decrypts on read
    });

    it('does not silently re-activate a deactivated card when editing without the flag', function () {
        $card = BankCard::factory()->inactive()->create(['title' => 'Sleeping card']);

        $this->put(cardpay_test_url('cards/').$card->id, [
            'title' => 'Awake card',
            'bank_name' => 'Saman',
            'card_holder_name' => 'Ali Rezaei',
        ])->assertRedirect();

        expect($card->fresh()->is_active)->toBeFalse()
            ->and($card->fresh()->title)->toBe('Awake card');
    });
});

describe('sms parsers + live test (§FR-6)', function () {
    it('creates a parser from comma-separated keywords', function () {
        $this->post(cardpay_test_url('parsers'), [
            'name' => 'Mellat deposit',
            'bank_name' => 'Mellat',
            'amount_regex' => '/واریز\s+(?<amount>[0-9,]+)/u',
            'positive_keywords' => "واریز, افزایش موجودی\nواریز به",
            'negative_keywords' => 'برداشت',
            'is_active' => '1',
        ])->assertRedirect();

        $parser = SmsParser::query()->where('name', 'Mellat deposit')->sole();
        expect($parser->positive_keywords)->toBe(['واریز', 'افزایش موجودی', 'واریز به'])
            ->and($parser->negative_keywords)->toBe(['برداشت']);
    });

    it('live-tests a real deposit WITHOUT persisting anything new', function () {
        $before = SmsParser::query()->count();

        $this->post(cardpay_test_url('parsers/').$this->parser->id.'/live-test', [
            'test_text' => 'بانک سامان واریز مبلغ ۱٬۰۰۰٬۰۰۰ ریال به حساب شما',
            'test_sender' => '+98555',
        ])->assertRedirect()->assertSessionHas('live_test');

        $result = session('live_test');
        expect($result['status'])->toBe('parsed')
            ->and($result['amount'])->toBe(1000000);

        // No sender pattern on this preset → sender ignored.
        expect(SmsParser::query()->count())->toBe($before);
    });

    it('live-test flags a withdrawal as ignored', function () {
        $this->post(cardpay_test_url('parsers/').$this->parser->id.'/live-test', [
            'test_text' => 'بانک سامان برداشت مبلغ ۵۰۰ ریال از حساب شما',
        ])->assertRedirect();

        expect(session('live_test')['status'])->toBe('ignored');
    });
});

describe('applications + key rotation (§FR-3)', function () {
    it('creates an application and flashes the secret exactly once', function () {
        $this->post(cardpay_test_url('applications'), [
            'name' => 'My Shop',
            'token_digits' => '3',
            'payment_expiration_minutes' => '30',
            'webhook_url' => 'https://shop.example/hooks',
            'is_active' => '1',
        ])->assertRedirect();

        $app = Application::query()->where('name', 'My Shop')->sole();
        $key = ApplicationApiKey::query()->where('application_id', $app->id)->sole();

        // Secret stored encrypted: raw column is not the plaintext.
        expect($key->getRawOriginal('secret_encrypted'))->not->toBe(session('revealed_secret')['secret'] ?? '');

        // The flash carries the one-time reveal.
        $revealed = session('revealed_secret');
        expect($revealed['public_key'])->toBe($key->public_key)
            ->and(Crypto::fingerprint($revealed['secret']))->toBe($key->secret_fingerprint);

        // Flash is consumed by the next request — never shown twice.
        $this->get(cardpay_test_url('applications'));
        expect(session('revealed_secret'))->toBeNull();
    });

    it('rotates credentials: old key revoked, new secret fingerprint differs', function () {
        $app = Application::factory()->create();
        $original = ApplicationApiKey::query()->create([
            'application_id' => $app->id,
            'public_key' => 'pk_old_'.Str::lower(Str::random(16)),
            'secret_encrypted' => 'old-secret-material',
            'secret_fingerprint' => Crypto::fingerprint('old-secret-material'),
            'is_active' => true,
        ]);

        $this->post(cardpay_test_url('applications/').$app->id.'/rotate')->assertRedirect();

        expect($original->fresh()->revoked_at)->not->toBeNull()
            ->and($original->fresh()->is_active)->toBeFalse();

        $new = ApplicationApiKey::query()->where('application_id', $app->id)->whereNull('revoked_at')->sole();
        expect(Crypto::fingerprint(session('revealed_secret')['secret']))->toBe($new->secret_fingerprint)
            ->and(AuditLog::query()->where('action', 'application.key_rotated')->count())->toBe(1);
    });
});

describe('devices (§FR-5)', function () {
    it('creates a device bound to a card, secret encrypted + shown once', function () {
        $card = BankCard::factory()->create();

        $this->post(cardpay_test_url('devices'), [
            'name' => 'Kitchen phone',
            'platform' => 'android',
            'bank_card_id' => (string) $card->id,
            'is_active' => '1',
        ])->assertRedirect();

        $device = Device::query()->where('name', 'Kitchen phone')->sole();
        $revealed = session('revealed_secret');

        expect($revealed['device_key'])->toBe($device->device_key)
            ->and(Crypto::fingerprint($revealed['secret']))->toBe($device->secret_fingerprint)
            ->and($device->getRawOriginal('device_secret_encrypted'))->not->toBe($revealed['secret']);
    });

    it('revokes permanently: a revoked device fails isUsable forever', function () {
        $card = BankCard::factory()->create();
        $device = Device::query()->create([
            'name' => 'Old relay',
            'platform' => 'android',
            'device_key' => 'dk_revokeme'.Str::lower(Str::random(12)),
            'device_secret_encrypted' => 's',
            'secret_fingerprint' => str_repeat('c', 64),
            'bank_card_id' => $card->id,
            'is_active' => true,
        ]);

        $this->post(cardpay_test_url('devices/').$device->id.'/revoke')->assertRedirect();

        expect($device->fresh()->isUsable())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'device.revoked')->count())->toBe(1);

        // Revoking again is idempotent — no duplicate audit entry.
        $this->post(cardpay_test_url('devices/').$device->id.'/revoke')->assertRedirect();
        expect(AuditLog::query()->where('action', 'device.revoked')->count())->toBe(1);
    });
});
