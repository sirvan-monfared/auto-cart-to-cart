<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Middleware;

use CartBecart\CardPay\Contracts\GatewayUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel gate (§FR-2): the session user must be an active admin.
 * Everyone else — guest, non-admin role, or deactivated account — is sent to
 * the login form. Applied to every /admin route via the `admin` alias.
 *
 * The user identity is the HOST's configured model (cardpay.user.model) —
 * the package only requires it to satisfy the GatewayUser contract.
 */
final class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof GatewayUser && $user->isActiveAdmin()) {
            return $next($request);
        }

        return redirect()->route('login');
    }
}
