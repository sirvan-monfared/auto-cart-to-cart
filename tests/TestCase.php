<?php

namespace CartBecart\CardPay\Tests;

use CartBecart\CardPay\Providers\CardPayServiceProvider;
use CartBecart\CardPay\Support\Edition;
use CartBecart\CardPay\Tests\Support\TestUser;
use Flux\FluxServiceProvider;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            FluxServiceProvider::class,
            CardPayServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cardpay.user.model', TestUser::class);
        $app['config']->set('cardpay.path', 'cardpay');
        $app['config']->set('cardpay.route_as', 'cardpay');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(realpath(__DIR__.'/../vendor/orchestra/testbench-core/laravel/migrations'));
        $this->loadMigrationsFrom(realpath(__DIR__.'/../src/Database/Migrations'));
        $this->loadMigrationsFrom(realpath(__DIR__.'/../database/user-migrations'));

        // Feature-scoped tables (cp_audit_logs, cp_settings). loadMigrationsFrom
        // is not recursive, so the directory is registered explicitly; the
        // migration itself skips whichever table its feature has turned off.
        if (Edition::enabled('audit') || Edition::enabled('db_settings')) {
            $this->loadMigrationsFrom(realpath(__DIR__.'/../src/Database/Migrations/Optional'));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('login')) {
            Route::get('/login', fn () => 'login')->name('login');
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! class_exists(Features::class)) {
            $this->markTestSkipped($message ?? 'Fortify is not installed.');

            return;
        }

        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
