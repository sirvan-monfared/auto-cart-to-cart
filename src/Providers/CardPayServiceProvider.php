<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Providers;

use CartBecart\CardPay\Console\InstallCommand;
use CartBecart\CardPay\Console\PublishCommand;
use CartBecart\CardPay\Services\Drivers\CardTransferDriver;
use CartBecart\CardPay\Services\Drivers\DriverRegistry;
use CartBecart\CardPay\Services\Drivers\PaymentDriver;
use CartBecart\CardPay\Services\Security\Crypto;
use CartBecart\CardPay\Services\Security\HmacAuthenticator;
use CartBecart\CardPay\Services\Webhooks\DatabaseWebhookEmitter;
use CartBecart\CardPay\Services\Webhooks\HttpWebhookProcessor;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;
use CartBecart\CardPay\Services\Webhooks\WebhookProcessor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the CardPay gateway into a host application: config merge, domain
 * singletons, views/lang/migrations under the cardpay:: namespaces, middleware
 * aliases, routes, publishing, and the install/publish commands.
 */
class CardPayServiceProvider extends ServiceProvider
{
    /**
     * Middleware aliases this package contributes. Registered in boot() via
     * the Router so the host doesn't have to touch bootstrap/app.php.
     */
    public const ALIASES = [
        'merchant.hmac' => \CartBecart\CardPay\Http\Middleware\MerchantHmacAuth::class,
        'device.hmac' => \CartBecart\CardPay\Http\Middleware\DeviceHmacAuth::class,
        'device.shortcut' => \CartBecart\CardPay\Http\Middleware\DeviceShortcutAuth::class,
        'admin' => \CartBecart\CardPay\Http\Middleware\AdminAuth::class,
        'installed' => \CartBecart\CardPay\Http\Middleware\EnsureInstalled::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/cardpay.php', 'cardpay');

        // Post-login destination + username login: OVERRIDE Fortify's defaults
        // ('email', '/dashboard'). Fortify's own config file already defines
        // these keys, so mergeConfigFrom would be a no-op — set() applies the
        // package's values while a host that published fortify.php AND loads
        // after us can still win (provider order decides; document in README).
        config()->set('fortify.username', 'username');
        config()->set('fortify.lowercase_usernames', true);
        config()->set('fortify.home', '/admin');
        

        // Single instance so the AES key is derived once per request, and so
        // tests can rebind it with a known key.
        $this->app->singleton(Crypto::class, fn (): Crypto => new Crypto);

        // HMAC request authenticator. Tolerance and nonce bounds are read
        // once from config; the same subject-agnostic core serves both the
        // merchant and device surfaces via their HmacScheme.
        $this->app->singleton(HmacAuthenticator::class, fn (): HmacAuthenticator => new HmacAuthenticator(
            tolerance: (int) config('cardpay.hmac.timestamp_tolerance', 300),
            nonceMin: (int) config('cardpay.hmac.nonce_min', 12),
            nonceMax: (int) config('cardpay.hmac.nonce_max', 190),
        ));

        // Webhook emission (durable, idempotent event rows). HTTP delivery is
        // wired separately; the recognition core only depends on the interface.
        $this->app->bind(WebhookEmitter::class, DatabaseWebhookEmitter::class);

        // Webhook HTTP delivery: signed POSTs with the retry ladder, executed
        // only inside budgeted maintenance (terminable middleware), never
        // inline on the recognition path.
        $this->app->bind(WebhookProcessor::class, HttpWebhookProcessor::class);

        // Payment driver registry: the active method comes from
        // config('cardpay.driver'). New gateway types register here — no
        // controller or state-machine change required.
        $this->app->singleton(DriverRegistry::class, function ($app): DriverRegistry {
            return new DriverRegistry([
                $app->make(CardTransferDriver::class)->name() => $app->make(CardTransferDriver::class),
            ], (string) config('cardpay.driver', 'card_transfer'));
        });

        $this->app->bind(PaymentDriver::class, fn ($app): PaymentDriver => $app->make(DriverRegistry::class)->active());

        // Package factories resolve from the package namespace.
        Factory::guessFactoryNamesUsing(fn (string $modelName): string => 'CartBecart\\CardPay\\Database\\Factories\\'.class_basename($modelName).'Factory');

        // Register the package's pages/layouts as Livewire component namespaces
        // so Route::livewire('settings/profile', 'cardpay::pages.settings.profile')
        // and the Volt single-file components resolve from the package views.
        // config()->set (not merge) APPENDS to Livewire's own defaults, which
        // load later via LivewireServiceProvider::register().
        config()->set('livewire.component_namespaces.cardpay', __DIR__.'/../../resources/views/pages');

        // Full-page Livewire components (settings pages) render inside the
        // package's app layout instead of Livewire's layouts::app default.
        config()->set('livewire.component_layout', 'cardpay::layouts.app');
    }

    public function boot(): void
    {
        // Apply the package's API exception rendering to whatever exception
        // handler the host bound (no-op if the host already wired it via
        // withExceptions + ApiExceptionRenderer::configure).
        $this->callAfterResolving(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            fn ($handler) => \CartBecart\CardPay\Http\ApiExceptionRenderer::attachHandler($handler),
        );

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'cardpay');
        // JSON string-key translations (lang/{locale}.json) for the plain
        // __('English string') calls, plus the namespaced group file for the
        // dynamic __('cardpay::settings.section.…') lookups.
        $this->loadJsonTranslationsFrom(__DIR__.'/../../resources/lang');
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'cardpay');
        $this->loadMigrationsFrom(__DIR__.'/../../src/Database/Migrations');

        // Anonymous components. Registering the package's views root as a
        // prefix-less component path means dot-notation tags map onto it:
        //   <x-layouts.app>      → resources/views/layouts/app.blade.php
        //   <x-app-logo>         → resources/views/components/app-logo.blade.php
        $this->callAfterResolving('blade.compiler', function ($blade): void {
            $blade->anonymousComponentPath(__DIR__.'/../../resources/views');
            $blade->anonymousComponentPath(__DIR__.'/../../resources/views/components');

            // Custom Flux icons/views shipped by the package (flux:icon.*).
            // Registered under the same 'flux' prefix Flux uses for its stubs —
            // hints append, so Flux stubs resolve first and package overrides
            // fill the gaps.
            if (is_dir(__DIR__.'/../../resources/views/flux')) {
                $blade->anonymousComponentPath(__DIR__.'/../../resources/views/flux', 'flux');
            }
        });

        $this->registerMiddleware();
        $this->registerRoutes();
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, PublishCommand::class]);
        }
    }

    private function registerMiddleware(): void
    {
        $router = $this->app->make(\Illuminate\Routing\Router::class);

        foreach (self::ALIASES as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }

        // §SR-8: strict CSP + security headers on every response, and the
        // cron-free heartbeat — budgeted maintenance runs in terminate(),
        // after every response is flushed, on web and API alike.
        $router->pushMiddlewareToGroup('web', \CartBecart\CardPay\Http\Middleware\SecurityHeaders::class);
        $router->pushMiddlewareToGroup('web', \CartBecart\CardPay\Http\Middleware\RunLazyMaintenance::class);
        $router->pushMiddlewareToGroup('api', \CartBecart\CardPay\Http\Middleware\SecurityHeaders::class);
        $router->pushMiddlewareToGroup('api', \CartBecart\CardPay\Http\Middleware\RunLazyMaintenance::class);
    }

    private function registerRoutes(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')
            ->group(__DIR__.'/../../routes/web.php');
        \Illuminate\Support\Facades\Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../../routes/api.php');

        // Self-served package assets (CSS/JS dist + fonts) under /vendor/cardpay/*.
        // Served through PHP so a host needs no symlink/copy step; cache-busting
        // comes from the ?v=<md5> query the <x-cardpay::assets> component emits.
        \Illuminate\Support\Facades\Route::get('/vendor/cardpay/{path}', function (string $path) {
            $base = realpath(__DIR__.'/../../resources/dist');
            $fonts = realpath(__DIR__.'/../../public/fonts');

            $candidates = array_filter([
                $base !== false ? realpath($base.'/'.$path) : false,
                $fonts !== false ? realpath($fonts.'/'.$path) : false,
            ]);

            foreach ($candidates as $candidate) {
                if ($candidate === false) {
                    continue;
                }
                if (! str_starts_with($candidate, $base.DIRECTORY_SEPARATOR) && ! str_starts_with($candidate, $fonts.DIRECTORY_SEPARATOR)) {
                    abort(404); // path traversal guard
                }
                $ext = pathinfo($candidate, PATHINFO_EXTENSION);
                $mime = match ($ext) {
                    'css' => 'text/css; charset=utf-8',
                    'js' => 'application/javascript; charset=utf-8',
                    'woff2' => 'font/woff2',
                    'svg' => 'image/svg+xml',
                    default => abort(404),
                };

                return response()->file($candidate, [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'public, max-age=31536000, immutable',
                ]);
            }

            abort(404);
        })->where('path', '.*')->name('cardpay.asset');
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../../config/cardpay.php' => config_path('cardpay.php'),
        ], 'cardpay-config');

        $this->publishes([
            __DIR__.'/../../database/user-migrations' => database_path('migrations'),
        ], 'cardpay-user-migrations');

        // Legacy tag for hosts that prefer real files over the self-serving route.
        $this->publishes([
            __DIR__.'/../../resources/dist' => public_path('vendor/cardpay'),
            __DIR__.'/../../public/fonts' => public_path('fonts'),
        ], 'cardpay-assets');

        $this->publishes([
            __DIR__.'/../../resources/views' => resource_path('views/vendor/cardpay'),
        ], 'cardpay-views');

        $this->publishes([
            __DIR__.'/../../resources/lang' => lang_path('vendor/cardpay'),
        ], 'cardpay-lang');
    }
}
