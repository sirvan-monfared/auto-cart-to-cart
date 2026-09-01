<?php

namespace CartBecart\CardPay\Tests;

use CartBecart\CardPay\Providers\CardPayFortifyServiceProvider;
use CartBecart\CardPay\Providers\CardPayServiceProvider;
use Laravel\Fortify\Features;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The providers the package registers in the testbench application,
     * plus the runtime dependencies (Livewire, Fortify) that auto-discovery
     * would normally load in a host app.
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            \Flux\FluxServiceProvider::class,
            \Laravel\Fortify\FortifyServiceProvider::class,
            CardPayServiceProvider::class,
            CardPayFortifyServiceProvider::class,
        ];
    }

    /**
     * Configure the testbench application (sqlite in-memory from phpunit.xml).
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cardpay.user.model', \CartBecart\CardPay\Tests\Support\TestUser::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('fortify.features', [
            'login',
            'passwordReset',
            'emailVerification',
            'passwordConfirmation',
            'twoFactorAuthentication',
            'passkeys',
        ]);
    }

    /**
     * Register the package's full migration set before RefreshDatabase's
     * migrate:fresh runs: the "host" users/cache/jobs tables (testbench
     * workbench), the cp_* domain tables, then the published users extension
     * and 2FA/passkeys columns. Because these are registered as migrator
     * paths, migrate:fresh executes them in filename order — which is
     * dependency order.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(realpath(__DIR__.'/../vendor/orchestra/testbench-core/laravel/migrations'));
        $this->loadMigrationsFrom(realpath(__DIR__.'/../src/Database/Migrations'));
        $this->loadMigrationsFrom(realpath(__DIR__.'/../database/user-migrations'));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
