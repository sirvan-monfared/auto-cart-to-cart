<?php

declare(strict_types=1);

use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Panel access gate — host session + cardpay.access
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('cardpay panel gate', function () {
    it('admits an authenticated active admin', function () {
        $this->actingAs(User::factory()->create());

        $this->get(cardpay_test_url())->assertOk();
    });

    it('redirects guests to login', function () {
        $this->get(cardpay_test_url())->assertRedirect(route('login'));
    });

    it('returns 403 for non-admin roles', function () {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(cardpay_test_url())->assertForbidden();
    });

    it('returns 403 for deactivated admins even mid-session', function () {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $admin->forceFill(['is_active' => false])->save();

        $this->get(cardpay_test_url())->assertForbidden();
    });
});
