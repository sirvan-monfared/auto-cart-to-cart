<?php

namespace CartBecart\CardPay\Tests\Feature\Auth;

use CartBecart\CardPay\Tests\Support\TestUser as User;
use CartBecart\CardPay\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('CardPay delegates authentication and settings to the host application.');
    }

    public function test_two_factor_challenge_redirects_to_login_when_not_authenticated(): void
    {
        $response = $this->get(route('two-factor.login'));

        $response->assertRedirect(route('login'));
    }

    public function test_two_factor_challenge_can_be_rendered(): void
    {
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));
    }
}
