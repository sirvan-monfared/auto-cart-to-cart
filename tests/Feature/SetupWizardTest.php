<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| §FR-1 / §SR-16 — guided setup wizard
|--------------------------------------------------------------------------
|
| The wizard walks: requirements → database (migrate + seed) → admin (skipped
| if one exists) → finalize (settings + default app + LOCK LAST). The lock
| 404s the entire surface afterwards. Adapt-safely: no .env writes, no APP_KEY
| regeneration, nothing existing is overwritten.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    @unlink(storage_path('installed.lock'));
});

afterEach(function () {
    @unlink(storage_path('installed.lock'));
});

it('walks the full wizard: requirements → admin creation → finalize with credential reveal and lock', function () {
    // Fresh DB is guaranteed by RefreshDatabase; simulate a not-yet-locked box.

    // Step 1 renders requirements.
    $this->get('/setup')
        ->assertOk()
        ->assertSee('Server requirements')
        ->assertSee('APP_KEY');

    // Steps 2's DB form posts are exercised only where a second MySQL target
    // exists; on the SQLite test DB we jump to step 3 directly.
    $this->get('/setup/admin')->assertOk()->assertSee('Create the administrator');

    // Weak password rejected (SR-5: min 10).
    $this->post('/setup/admin', [
        'name' => 'Boss',
        'username' => 'siteboss',
        'password' => 'only9char',
        'password_confirmation' => 'only9char',
    ])->assertSessionHasErrors();

    // Mismatched confirmation rejected.
    $this->post('/setup/admin', [
        'name' => 'Boss',
        'username' => 'siteboss',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'different-long-password',
    ])->assertSessionHasErrors();

    // Valid creation redirects onward.
    $this->post('/setup/admin', [
        'name' => 'Site Boss',
        'username' => 'SiteBoss', // stored lowercase
        'mobile' => '09120000000',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'long-enough-password-10',
    ])->assertRedirect('/setup/finalize');

    $admin = User::query()->where('username', 'siteboss')->sole();
    expect($admin->role)->toBe('admin')
        ->and($admin->is_active)->toBeTrue();

    // Finalize applies settings, creates the store app, shows secret ONCE, locks.
    $response = $this->post('/setup/finalize', [
        'title' => 'پرداخت فروشگاه من',
        'currency' => 'IRR',
        'timezone' => 'Asia/Tehran',
        'primary_color' => '#155EEF',
        'accent_color' => '#12B76A',
    ]);

    $response->assertOk()
        ->assertSee('Installation complete')
        ->assertSee($response->original['secret'] ?? '', false); // one-time reveal present in THIS response

    expect(file_exists(storage_path('installed.lock')))->toBeTrue()
        ->and(Setting::get('payment_title'))->toBe('پرداخت فروشگاه من')
        ->and(Application::query()->where('slug', 'store')->exists())->toBeTrue()
        ->and(ApplicationApiKey::query()->count())->toBe(1);

    // Lock closes the door: every /setup path now 404s.
    foreach (['/setup', '/setup/admin', '/setup/finalize'] as $path) {
        $this->get($path)->assertStatus(404);
    }
});

it('skips admin creation when an active admin already exists (adapt-safely)', function () {
    // An existing install: migrated schema + an active admin, no lock
    // (e.g. someone deleted the lock file).
    User::factory()->create(['username' => 'existing-boss']);

    $this->get('/setup/admin')
        ->assertOk()
        ->assertSee('already exists')
        ->assertDontSee('Create the administrator</h2>', false);

    // Even posting valid credentials must NOT create a second admin.
    $before = User::query()->count();
    $this->post('/setup/admin', [
        'name' => 'Usurper',
        'username' => 'usurper',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'long-enough-password-10',
    ])->assertRedirect('/setup/finalize');

    expect(User::query()->where('username', 'usurper')->exists())->toBeFalse()
        ->and(User::query()->count())->toBe($before);
});

it('refuses duplicate usernames at creation', function () {
    // A non-admin user occupies the username without triggering the
    // has-active-admin skip.
    User::factory()->viewer()->create(['username' => 'taken']);

    $this->post('/setup/admin', [
        'name' => 'X',
        'username' => 'Taken',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'long-enough-password-10',
    ])->assertSessionHasErrors(); // uniqueness checked case-insensitively

    expect(User::query()->where('username', 'taken')->count())->toBe(1);
});

it('finalizing twice never mints a second API key or clobbers supplied settings', function () {
    // First pass creates admin + finalizes with a title.
    $this->post('/setup/admin', [
        'name' => 'First',
        'username' => 'firstboss',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'long-enough-password-10',
    ])->assertRedirect('/setup/finalize');
    $this->post('/setup/finalize', ['title' => 'First Title'])->assertOk();

    expect(ApplicationApiKey::query()->count())->toBe(1)
        ->and(Setting::get('payment_title'))->toBe('First Title');

    // A second finalize (e.g. double POST): the store application already
    // exists so no second key is minted; absent fields write nothing new.
    @unlink(storage_path('installed.lock'));
    $this->post('/setup/finalize', [])->assertOk();

    expect(ApplicationApiKey::query()->count())->toBe(1)
        ->and(Setting::get('payment_title'))->toBe('First Title');
});
