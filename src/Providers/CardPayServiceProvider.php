<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Providers;

use CartBecart\CardPay\Console\InstallCommand;
use CartBecart\CardPay\Console\PublishCommand;
use CartBecart\CardPay\Console\RotateApiKeyCommand;
use CartBecart\CardPay\Contracts\GatewayUser;
use CartBecart\CardPay\Http\ApiExceptionRenderer;
use CartBecart\CardPay\Http\Middleware\AdminAuth;
use CartBecart\CardPay\Http\Middleware\DeviceHmacAuth;
use CartBecart\CardPay\Http\Middleware\DeviceShortcutAuth;
use CartBecart\CardPay\Http\Middleware\EnsureInstalled;
use CartBecart\CardPay\Http\Middleware\MerchantHmacAuth;
use CartBecart\CardPay\Http\Middleware\RunLazyMaintenance;
use CartBecart\CardPay\Http\Middleware\SecurityHeaders;
use CartBecart\CardPay\Services\CardPayManager;
use CartBecart\CardPay\Services\Drivers\CardTransferDriver;
use CartBecart\CardPay\Services\Drivers\DriverRegistry;
use CartBecart\CardPay\Services\Drivers\PaymentDriver;
use CartBecart\CardPay\Services\Payments\PaymentService;
use CartBecart\CardPay\Services\Provisioning\GatewayProvisioner;
use CartBecart\CardPay\Services\Security\Crypto;
use CartBecart\CardPay\Services\Security\HmacAuthenticator;
use CartBecart\CardPay\Services\Webhooks\DatabaseWebhookEmitter;
use CartBecart\CardPay\Services\Webhooks\HttpWebhookProcessor;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;
use CartBecart\CardPay\Services\Webhooks\WebhookProcessor;
use CartBecart\CardPay\Support\Edition;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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
        'merchant.hmac' => MerchantHmacAuth::class,
        'device.hmac' => DeviceHmacAuth::class,
        'device.shortcut' => DeviceShortcutAuth::class,
        'cardpay.access' => AdminAuth::class,
        'admin' => AdminAuth::class,
        'installed' => EnsureInstalled::class,
    ];

    public function register(): void
    {
        require_once __DIR__.'/../Support/helpers.php';

        $this->mergeConfigFrom(__DIR__.'/../../config/cardpay.php', 'cardpay');

        $this->app->singleton(Crypto::class, fn (): Crypto => new Crypto);

        $this->app->singleton(HmacAuthenticator::class, fn (): HmacAuthenticator => new HmacAuthenticator(
            tolerance: (int) config('cardpay.hmac.timestamp_tolerance', 300),
            nonceMin: (int) config('cardpay.hmac.nonce_min', 12),
            nonceMax: (int) config('cardpay.hmac.nonce_max', 190),
        ));

        $this->app->bind(WebhookEmitter::class, DatabaseWebhookEmitter::class);
        $this->app->bind(WebhookProcessor::class, HttpWebhookProcessor::class);

        $this->app->singleton(DriverRegistry::class, function ($app): DriverRegistry {
            return new DriverRegistry([
                $app->make(CardTransferDriver::class)->name() => $app->make(CardTransferDriver::class),
            ], (string) config('cardpay.driver', 'card_transfer'));
        });

        $this->app->bind(PaymentDriver::class, fn ($app): PaymentDriver => $app->make(DriverRegistry::class)->active());

        $this->app->singleton(GatewayProvisioner::class);

        $this->app->singleton(CardPayManager::class, fn ($app): CardPayManager => new CardPayManager(
            $app->make(PaymentService::class),
            $app->make(GatewayProvisioner::class),
        ));
        $this->app->alias(CardPayManager::class, 'cardpay');

        Factory::guessFactoryNamesUsing(fn (string $modelName): string => 'CartBecart\\CardPay\\Database\\Factories\\'.class_basename($modelName).'Factory');

        // Livewire pages are panel-only; lite never boots them.
        if (Edition::enabled('panel')) {
            config()->set('livewire.component_namespaces.cardpay', __DIR__.'/../../resources/views/pages');
        }
    }

    public function boot(): void
    {
        $this->callAfterResolving(
            ExceptionHandler::class,
            fn ($handler) => ApiExceptionRenderer::attachHandler($handler),
        );

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'cardpay');
        $this->loadJsonTranslationsFrom(__DIR__.'/../../resources/lang');
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'cardpay');

        $this->registerMigrations();

        // Blade component paths exist to serve the panel's Flux/Livewire views.
        // The hosted checkout is a self-contained template, so lite skips them.
        if (Edition::enabled('panel')) {
            $this->callAfterResolving('blade.compiler', function ($blade): void {
                $blade->anonymousComponentPath(__DIR__.'/../../resources/views');
                $blade->anonymousComponentPath(__DIR__.'/../../resources/views/components');

                if (is_dir(__DIR__.'/../../resources/views/flux')) {
                    $blade->anonymousComponentPath(__DIR__.'/../../resources/views/flux', 'flux');
                }
            });
        }

        $this->registerGate();
        $this->registerMiddleware();
        $this->registerRoutes();
        $this->registerPublishing();

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, PublishCommand::class, RotateApiKeyCommand::class]);
        }
    }

    /**
     * Core schema always loads. cp_audit_logs and cp_settings live in a
     * separate directory that only loads when their features are on, so a lite
     * install simply never creates them — and turning a feature back on later
     * makes the migration pending, so `migrate` adds the table with no manual
     * SQL and no edit to an already-run migration.
     */
    private function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../src/Database/Migrations');

        if (Edition::enabled('audit') || Edition::enabled('db_settings')) {
            $this->loadMigrationsFrom(__DIR__.'/../../src/Database/Migrations/Optional');
        }
    }

    private function registerGate(): void
    {
        $gate = (string) config('cardpay.auth.gate', 'cardpay.access');

        if (Gate::has($gate)) {
            return;
        }

        Gate::define($gate, function ($user): bool {
            $roles = config('cardpay.auth.roles', ['super_admin', 'admin']);

            if (method_exists($user, 'hasRole')) {
                foreach ($roles as $role) {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                }
            }

            if (isset($user->role) && in_array($user->role, $roles, true)) {
                return ! isset($user->is_active) || (bool) $user->is_active;
            }

            return $user instanceof GatewayUser && $user->isActiveAdmin();
        });
    }

    private function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);

        foreach (self::ALIASES as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }

        $router->pushMiddlewareToGroup('web', SecurityHeaders::class);
        $router->pushMiddlewareToGroup('web', RunLazyMaintenance::class);
        $router->pushMiddlewareToGroup('api', SecurityHeaders::class);
        $router->pushMiddlewareToGroup('api', RunLazyMaintenance::class);
    }

    private function registerRoutes(): void
    {
        $path = cardpay_path();
        $routeAs = trim((string) config('cardpay.route_as', 'cardpay'), '.');

        // Hosted checkout: the customer surface, not an admin one — lite keeps it.
        if (Edition::enabled('checkout')) {
            Route::middleware('web')
                ->group(__DIR__.'/../../routes/web.php');
        }

        if (Edition::enabled('setup_wizard')) {
            Route::middleware(['web', 'installed'])
                ->prefix($path.'/setup')
                ->as('cardpay.setup.')
                ->group(__DIR__.'/../../routes/setup.php');
        }

        if (Edition::enabled('panel')) {
            Route::middleware(['web', 'auth', 'cardpay.access'])
                ->prefix($path)
                ->as($routeAs.'.')
                ->group(__DIR__.'/../../routes/admin.php');
        }

        // routes/api.php gates its own groups per feature (merchant / device /
        // public status), so the file registers whatever this edition exposes.
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../../routes/api.php');

        $this->registerAdminApiRoutes();
        $this->registerAssetRoute();
    }

    /**
     * The Admin JSON API: every panel capability as JSON so a host application
     * can render the gateway's admin inside its own back office. This is the
     * ONLY admin surface a lite install has.
     */
    private function registerAdminApiRoutes(): void
    {
        if (! Edition::enabled('admin_api')) {
            return;
        }

        $middleware = config('cardpay.admin_api.middleware', ['web', 'auth', 'cardpay.access']);

        Route::middleware(is_array($middleware) ? $middleware : ['web', 'auth', 'cardpay.access'])
            ->prefix(trim((string) config('cardpay.admin_api.prefix', 'api/cardpay/admin'), '/'))
            ->as((string) config('cardpay.admin_api.route_as', 'cardpay.admin-api.'))
            ->group(__DIR__.'/../../routes/admin-api.php');
    }

    /**
     * Serves packaged CSS/JS/fonts when they have not been published to
     * public/. The hosted checkout depends on it, so it outlives the panel.
     */
    private function registerAssetRoute(): void
    {
        if (! Edition::enabled('checkout') && ! Edition::enabled('panel')) {
            return;
        }

        Route::get('/vendor/cardpay/{path}', function (string $path) {
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
                    abort(404);
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
