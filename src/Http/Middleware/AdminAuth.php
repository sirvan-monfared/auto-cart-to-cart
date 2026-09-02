<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel access gate: requires an authenticated host session and authorization
 * via the configurable Laravel Gate (default: cardpay.access).
 */
final class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return cardpay_login_redirect();
        }

        $gate = (string) config('cardpay.auth.gate', 'cardpay.access');

        if (! Auth::user()?->can($gate)) {
            abort(403);
        }

        return $next($request);
    }
}
