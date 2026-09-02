<?php

namespace CartBecart\CardPay\Tests\Feature;

use CartBecart\CardPay\Tests\Support\TestUser as User;
use CartBecart\CardPay\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_the_cardpay_panel(): void
    {
        $this->get(cardpay_test_url())->assertRedirect(route('login'));
    }

    public function test_authenticated_admins_can_access_the_cardpay_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(cardpay_test_url())->assertOk();
    }

    public function test_non_admins_receive_forbidden_from_the_panel(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(cardpay_test_url())->assertForbidden();
    }
}
