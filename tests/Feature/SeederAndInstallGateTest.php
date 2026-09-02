<?php

declare(strict_types=1);

use CartBecart\CardPay\Database\Seeders\DatabaseSeeder;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Models\SmsParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds defaults idempotently: two runs leave identical state', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Setting::query()->where('setting_key', 'primary_color')->value('setting_value'))->toBe('#155EEF')
        ->and(Setting::query()->where('setting_key', 'accent_color')->value('setting_value'))->toBe('#12B76A')
        ->and(Setting::query()->where('setting_key', 'timezone')->value('setting_value'))->toBe('Asia/Tehran')
        ->and(Setting::query()->where('setting_key', 'payment_title')->where('is_public', true)->count())->toBe(1)
        ->and(Setting::query()->count())->toBe(13);

    expect(SmsParser::query()->where('bank_name', 'Saman')->count())->toBe(1)
        ->and(SmsParser::query()->where('name', 'Saman Bank deposit')->value('amount_regex'))
        ->toBe('/واریز\s+مبلغ\s+(?<amount>[0-9۰-۹,٬ ]+)\s*ریال/u');

    $app = Application::query()->where('slug', 'store')->sole();
    expect(ApplicationApiKey::query()->where('application_id', $app->id)->count())->toBe(1);
});

it('preserves admin-customized settings on reseed', function () {
    $this->seed(DatabaseSeeder::class);

    Setting::put('primary_color', '#FF0000', 'string', true);
    $this->seed(DatabaseSeeder::class);

    expect(Setting::query()->where('setting_key', 'primary_color')->value('setting_value'))->toBe('#FF0000');
});

describe('installer lock (§SR-16 / AC-9)', function () {
    beforeEach(function () {
        @unlink(storage_path('installed.lock'));
    });

    afterEach(function () {
        @unlink(storage_path('installed.lock'));
    });

    it('allows setup before installation', function () {
        $this->get(cardpay_setup_test_url())
            ->assertOk()
            ->assertSee('Server requirements');
    });

    it('404s setup after the lock file exists', function () {
        touch(storage_path('installed.lock'));

        $this->get(cardpay_setup_test_url())->assertStatus(404);
    });
});
