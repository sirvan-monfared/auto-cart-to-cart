<?php

declare(strict_types=1);

use CartBecart\CardPay\Support\Edition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

if (! function_exists('cardpay_edition')) {
    /**
     * The active distribution: 'full' (bundled panel) or 'lite' (API-only).
     */
    function cardpay_edition(): string
    {
        return Edition::current();
    }
}

if (! function_exists('cardpay_is_lite')) {
    function cardpay_is_lite(): bool
    {
        return Edition::isLite();
    }
}

if (! function_exists('cardpay_feature')) {
    /**
     * Whether a CardPay feature is active in this install.
     */
    function cardpay_feature(string $feature): bool
    {
        return Edition::enabled($feature);
    }
}

if (! function_exists('cardpay_admin_api_url')) {
    /**
     * Build a path under the Admin JSON API prefix.
     */
    function cardpay_admin_api_url(string $path = ''): string
    {
        $base = '/'.trim((string) config('cardpay.admin_api.prefix', 'api/cardpay/admin'), '/');
        $path = ltrim($path, '/');

        return $path === '' ? $base : $base.'/'.$path;
    }
}

if (! function_exists('cardpay_path')) {
    /**
     * URL path prefix for the CardPay panel (no leading/trailing slashes).
     */
    function cardpay_path(): string
    {
        return trim((string) config('cardpay.path', 'cardpay'), '/');
    }
}

if (! function_exists('cardpay_route_name')) {
    /**
     * Fully-qualified route name for a panel route segment.
     */
    function cardpay_route_name(string $name): string
    {
        $prefix = trim((string) config('cardpay.route_as', 'cardpay'), '.');

        return $prefix.'.'.ltrim($name, '.');
    }
}

if (! function_exists('cardpay_route')) {
    /**
     * Generate a URL for a CardPay panel route.
     */
    function cardpay_route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route(cardpay_route_name($name), $parameters, $absolute);
    }
}

if (! function_exists('cardpay_route_is')) {
    /**
     * Determine if the current route matches a CardPay panel route pattern.
     */
    function cardpay_route_is(string $pattern): bool
    {
        $prefix = trim((string) config('cardpay.route_as', 'cardpay'), '.').'.';

        if (! str_contains($pattern, '*') && ! str_contains($pattern, '.')) {
            $pattern = $prefix.$pattern;
        } elseif (! str_starts_with($pattern, $prefix)) {
            $pattern = $prefix.$pattern;
        }

        return request()->routeIs($pattern);
    }
}

if (! function_exists('cardpay_setup_route')) {
    /**
     * Generate a URL for a CardPay setup wizard route.
     */
    function cardpay_setup_route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route('cardpay.setup.'.ltrim($name, '.'), $parameters, $absolute);
    }
}

if (! function_exists('cardpay_url')) {
    /**
     * Build a path under the CardPay panel prefix.
     */
    function cardpay_url(string $path = ''): string
    {
        $base = '/'.cardpay_path();
        $path = ltrim($path, '/');

        return $path === '' ? $base : $base.'/'.$path;
    }
}

if (! function_exists('cardpay_login_redirect')) {
    /**
     * Redirect target for unauthenticated panel access.
     */
    function cardpay_login_redirect(): RedirectResponse
    {
        return Route::has('login')
            ? redirect()->route('login')
            : redirect('/login');
    }
}
