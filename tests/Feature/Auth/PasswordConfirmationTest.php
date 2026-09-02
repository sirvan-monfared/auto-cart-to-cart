<?php

namespace CartBecart\CardPay\Tests\Feature\Auth;

use CartBecart\CardPay\Tests\Support\TestUser as User;
use CartBecart\CardPay\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PasswordConfirmationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('CardPay delegates authentication and settings to the host application.');
    }

    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk();
    }
}
