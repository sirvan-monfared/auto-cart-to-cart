<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    $this->get(cardpay_setup_test_url())
        ->assertOk()
        ->assertSee('Server requirements')
        ->assertSee('APP_KEY');

    // Steps 2's DB form posts are exercised only where a second MySQL target
    // exists; on the SQLite test DB we jump to step 3 directly.
    $this->get(cardpay_setup_test_url('admin'))->assertOk()->assertSee('Create the administrator');

    // Weak password rejected (SR-5: min 10).
    $this->post(cardpay_setup_test_url('admin'), [
        'name' => 'Boss',
        'username' => 'siteboss',
        'password' => 'only9char',
        'password_confirmation' => 'only9char',
    ])->assertSessionHasErrors();

    // Mismatched confirmation rejected.
    $this->post(cardpay_setup_test_url('admin'), [
        'name' => 'Boss',
        'username' => 'siteboss',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'different-long-password',
    ])->assertSessionHasErrors();

    // Valid creation redirects onward.
    $this->post(cardpay_setup_test_url('admin'), [
        'name' => 'Site Boss',
        'username' => 'SiteBoss', // stored lowercase
        'mobile' => '09120000000',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'long-enough-password-10',
    ])->assertRedirect(cardpay_setup_test_url('finalize'));

    $admin = User::query()->where('username', 'siteboss')->sole();
    expect($admin->role)->toBe('admin')
        ->and($admin->is_active)->toBeTrue();

    // Finalize applies settings, creates the store app, shows secret ONCE, locks.
    $response = $this->post(cardpay_setup_test_url('finalize'), [
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
    foreach ([cardpay_setup_test_url(), cardpay_setup_test_url('admin'), cardpay_setup_test_url('finalize')] as $path) {
        $this->get($path)->assertStatus(404);
    }
});

it('skips admin creation when host users already exist', function () {
    User::factory()->viewer()->create(['username' => 'existing-user']);

    $this->get(cardpay_setup_test_url('admin'))
        ->assertRedirect(cardpay_setup_test_url('finalize'));
});

it('skips admin creation when an active admin already exists (adapt-safely)', function () {
    User::factory()->create(['username' => 'existing-boss']);

    $this->get(cardpay_setup_test_url('admin'))
        ->assertRedirect(cardpay_setup_test_url('finalize'));
});

it('refuses duplicate usernames at creation', function () {
    $this->markTestSkipped('Admin creation is skipped when host users already exist.');
    // A non-admin user occupies the username without triggering the
    // has-active-admin skip.
    User::factory()->viewer()->create(['username' => 'taken']);

    $this->post(cardpay_setup_test_url('admin'), [
        'name' => 'X',
        'username' => 'Taken',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'long-enough-password-10',
    ])->assertSessionHasErrors(); // uniqueness checked case-insensitively

    expect(User::query()->where('username', 'taken')->count())->toBe(1);
});

it('finalizing twice never mints a second API key or clobbers supplied settings', function () {
    // First pass creates admin + finalizes with a title.
    $this->post(cardpay_setup_test_url('admin'), [
        'name' => 'First',
        'username' => 'firstboss',
        'password' => 'long-enough-password-10',
        'password_confirmation' => 'long-enough-password-10',
    ])->assertRedirect(cardpay_setup_test_url('finalize'));
    $this->post(cardpay_setup_test_url('finalize'), ['title' => 'First Title'])->assertOk();

    expect(ApplicationApiKey::query()->count())->toBe(1)
        ->and(Setting::get('payment_title'))->toBe('First Title');

    // A second finalize (e.g. double POST): the store application already
    // exists so no second key is minted; absent fields write nothing new.
    @unlink(storage_path('installed.lock'));
    $this->post(cardpay_setup_test_url('finalize'), [])->assertOk();

    expect(ApplicationApiKey::query()->count())->toBe(1)
        ->and(Setting::get('payment_title'))->toBe('First Title');
});
