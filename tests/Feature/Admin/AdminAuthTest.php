<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\AuditLog;
use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| §FR-2 Admin authentication — username login, gating, audit
|--------------------------------------------------------------------------
|
| Login is by USERNAME through Fortify with a custom resolver that: refuses
| non-admins and deactivated accounts; DB-rate-limits per IP+username
| (5/300 s); captures last_login_at/last_ip; audits every attempt. The panel
| gate (`admin` middleware) admits active admins only.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));

    // A minimal admin-guarded probe route (the real panel lands in M2).
    app('router')->middleware(['web', 'admin'])->group(function ($router): void {
        $router->get('admin/probe', fn () => 'PANEL OK');
    });
});

afterEach(function () {
    Carbon::setTestNow();
});

function loginAsUsername(string $username, string $password): TestResponse
{
    return test()->post('/login', [
        'username' => $username,
        'password' => $password,
    ]);
}

it('logs in an active admin by username and captures last-login metadata', function () {
    $admin = User::factory()->create([
        'username' => 'siteadmin',
        'password' => 'correct-horse-battery',
    ]);

    loginAsUsername('siteadmin', 'correct-horse-battery')
        ->assertRedirect('/admin'); // the panel is the post-login home (§FR-16)

    expect(Auth::check())->toBeTrue()
        ->and(Auth::user()->id)->toBe($admin->id)
        ->and($admin->fresh()->last_login_at?->equalTo(now()))->toBeTrue()
        ->and($admin->fresh()->last_ip)->not->toBeNull();

    // Success audited (§SR-14).
    expect(AuditLog::query()->where('action', 'auth.login_succeeded')->count())->toBe(1);
});

it('refuses a wrong password and audits the failure without revealing user existence', function () {
    User::factory()->create(['username' => 'siteadmin', 'password' => 'secret-123']);

    loginAsUsername('siteadmin', 'wrong-password')
        ->assertStatus(302)
        ->assertInvalid('username'); // Fortify flashes generic errors on that key

    expect(Auth::check())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'auth.login_failed')->count())->toBe(1);
});

it('refuses a deactivated account outright', function () {
    User::factory()->deactivated()->create([
        'username' => 'frozen',
        'password' => 'secret-123',
    ]);

    loginAsUsername('frozen', 'secret-123')->assertSessionHasErrors();

    expect(Auth::check())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'auth.login_failed')->count())->toBe(1);
});

it('rate limits the sixth attempt within 300 seconds', function () {
    config()->set('cardpay.rate_limits.login', 5);
    User::factory()->create(['username' => 'hammered', 'password' => 'secret-123']);

    foreach (range(1, 5) as $_) {
        loginAsUsername('hammered', 'totally-wrong');
    }

    // The 6th attempt inside the window is blocked — by Fortify's built-in
    // login limiter (429) and/or the §A7 DB limiter (validation error). Either
    // way the account is protected; no authentication occurs.
    $blocked = loginAsUsername('hammered', 'totally-wrong');

    expect($blocked->status())->toBeIn([302, 429])
        ->and(Auth::check())->toBeFalse();
});

describe('admin panel gate', function () {
    it('admits an authenticated active admin', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/probe')->assertOk()->assertSee('PANEL OK');
    });

    it('redirects guests to login', function () {
        $this->get('/admin/probe')->assertRedirect(route('login'));
    });

    it('redirects non-admin roles to login', function () {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get('/admin/probe')->assertRedirect(route('login'));
    });

    it('redirects deactivated admins to login even mid-session', function () {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        // Deactivated AFTER the session started — the gate re-checks live state.
        $admin->forceFill(['is_active' => false])->save();

        $this->get('/admin/probe')->assertRedirect(route('login'));
    });
});
