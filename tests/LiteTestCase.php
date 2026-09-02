<?php

namespace CartBecart\CardPay\Tests;

/**
 * Boots the package in the LITE edition (§16).
 *
 * The edition is set during environment resolution, before providers register,
 * so route registration, the Livewire namespace, and the migration set all see
 * `lite` — exactly as a real single-shop install would. mergeConfigFrom lets
 * existing config win, so this value survives the package defaults.
 */
abstract class LiteTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cardpay.edition', 'lite');
    }
}
