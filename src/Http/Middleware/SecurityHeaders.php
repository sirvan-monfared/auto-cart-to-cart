<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on EVERY response (§SR-8).
 *
 * The CSP is strict: everything self-hosted, no CDNs, no inline scripts.
 * The checkout page's JS is a served file (/cardpay/checkout.js), so plain
 * script-src 'self' suffices — no hash pinning, no hash-sync maintenance
 * trap. style-src keeps 'unsafe-inline' for branding CSS variables only.
 * frame-ancestors 'none' plus X-Frame-Options DENY make clickjacking of the
 * payment page impossible.
 *
 * 'unsafe-eval' on script-src: the Livewire/Flux UI layer (sidebar collapse,
 * dropdown menus, modals, toasts, wire:navigate) runs on Alpine, whose
 * expression engine compiles HTML attribute expressions with new Function() —
 * impossible to serve under a strict CSP without it. Note that this does NOT
 * permit inline <script> execution: script files still come from 'self'.
 */
final class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::CSP);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
