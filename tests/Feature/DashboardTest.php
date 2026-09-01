<?php

namespace CartBecart\CardPay\Tests\Feature;

use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use CartBecart\CardPay\Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_the_legacy_dashboard_path_redirects_active_admins_to_the_panel(): void
    {
        // /dashboard is now a redirect; the real panel lives at /admin (§FR-16).
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertRedirect('/admin');

        $this->get('/admin')->assertOk();
    }

    public function test_non_admins_bounce_from_the_redirect_target_back_to_login(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        // The redirect chain lands on /admin, whose gate sends non-admins to login.
        $this->get(route('dashboard'))
            ->assertRedirect('/admin');

        $this->get('/admin')->assertRedirect(route('login'));
    }
}
