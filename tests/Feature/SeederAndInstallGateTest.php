<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Models\SmsParser;
use CartBecart\CardPay\Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| §FR-1 seeders (idempotent) + §SR-16 installer lock
|--------------------------------------------------------------------------
|
| Running the seed chain twice must converge to the same state: settings
| preserved, parser preset not duplicated, the default application's API key
| minted exactly once. The install gate 404s the setup surface once the lock
| file exists and allows it before.
|
*/

uses(RefreshDatabase::class);

it('seeds defaults idempotently: two runs leave identical state', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class); // second run must be a no-op

    // Settings exist exactly once each with public branding flags.
    expect(Setting::query()->where('setting_key', 'primary_color')->value('setting_value'))->toBe('#155EEF')
        ->and(Setting::query()->where('setting_key', 'accent_color')->value('setting_value'))->toBe('#12B76A')
        ->and(Setting::query()->where('setting_key', 'timezone')->value('setting_value'))->toBe('Asia/Tehran')
        ->and(Setting::query()->where('setting_key', 'payment_title')->where('is_public', true)->count())->toBe(1)
        ->and(Setting::query()->count())->toBe(13);

    // Saman preset exists exactly once.
    expect(SmsParser::query()->where('bank_name', 'Saman')->count())->toBe(1)
        ->and(SmsParser::query()->where('name', 'Saman Bank deposit')->value('amount_regex'))
        ->toBe('/واریز\s+مبلغ\s+(?<amount>[0-9۰-۹,٬ ]+)\s*ریال/u');

    // One store application; its API key was minted ONCE despite two runs.
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
        // Route a probe through a fake setup path registered only for this test.
        app('router')->middleware('installed')->group(function ($router): void {
            $router->get('setup', fn () => 'SETUP PAGE');
        });
    });

    it('allows setup before installation', function () {
        @unlink(storage_path('installed.lock'));

        $this->get('/setup')->assertOk()->assertSee('SETUP PAGE');
    });

    it('404s setup after the lock file exists', function () {
        touch(storage_path('installed.lock'));

        $this->get('/setup')->assertStatus(404);

        @unlink(storage_path('installed.lock'));
    });
});
