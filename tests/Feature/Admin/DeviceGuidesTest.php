<?php

declare(strict_types=1);

use CartBecart\CardPay\Tests\Support\TestUser as User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| §FR-5/§FR-9 — per-platform device onboarding guides
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

it('renders both device guides with pairing, signing recipe, and troubleshooting', function () {
    $this->actingAs(User::factory()->create());

    $android = $this->get(cardpay_test_url('guides/devices/android'))
        ->assertOk()
        ->assertSee('device_key')
        ->assertSee('X-Device-Signature')
        ->assertSee('hash_hmac')
        ->assertSee('invalid_device_signature'); // troubleshooting section

    expect($android->getContent())->toContain('dir="rtl"');

    $ios = $this->get(cardpay_test_url('guides/devices/ios-shortcut'))
        ->assertOk()
        ->assertSee('shortcut-sms')
        ->assertSee('X-Device-Secret')
        ->assertSee('duplicate=true')
        ->assertSee('invalid_device_key');

    expect($ios->getContent())->toContain('dir="rtl"');

    // Cross-links between the two guides.
    $android->assertSee(cardpay_test_url('guides/devices/ios-shortcut'));
    $ios->assertSee(cardpay_test_url('guides/devices/android'));
});

it('blocks guide pages from guests', function () {
    $this->get(cardpay_test_url('guides/devices/android'))->assertRedirect(route('login'));
    $this->get(cardpay_test_url('guides/devices/ios-shortcut'))->assertRedirect(route('login'));
});

it('links both device guides from the docs hub', function () {
    $this->actingAs(User::factory()->create());

    $hub = $this->get(cardpay_test_url('docs'))->assertOk();

    expect($hub->getContent())
        ->toContain(cardpay_test_url('guides/devices/android'))
        ->and($hub->getContent())->toContain(cardpay_test_url('guides/devices/ios-shortcut'));
});
