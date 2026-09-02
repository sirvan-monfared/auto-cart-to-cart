<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installer lock (§SR-16): once `storage/installed.lock` exists, the setup
 * surface is permanently disabled — a 404, indistinguishable from any other
 * unknown route. The gateway itself is unaffected either way.
 */
final class EnsureInstalled
{
    public const LOCK_PATH = 'installed.lock';

    public function handle(Request $request, Closure $next): Response
    {
        $setupPath = cardpay_path().'/setup';

        if ($request->is($setupPath) || $request->is($setupPath.'/*')) {
            if (! $this->installed()) {
                return $next($request);
            }

            abort(404);
        }

        return $next($request);
    }

    private function installed(): bool
    {
        return file_exists(storage_path(self::LOCK_PATH));
    }
}
