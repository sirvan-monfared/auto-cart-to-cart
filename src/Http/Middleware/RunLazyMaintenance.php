<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Middleware;

use CartBecart\CardPay\Services\Maintenance\LazyMaintenance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs budgeted maintenance AFTER the response is flushed (§FR-15 / plan §4).
 *
 * This is the cron-free heartbeat: expiry sweeps, reservation releases, due
 * webhook deliveries, and ephemeral-row purges all execute in terminate() —
 * off the user's latency budget entirely. The recognition path itself never
 * performs this work inline.
 *
 * terminate() is wrapped so a maintenance fault can never surface to anyone:
 * by then the real response is already gone, but a thrown error here would
 * still kill a PHP-FPM worker / poison logs. Fail silent, try again next hit.
 */
final class RunLazyMaintenance
{
    public function __construct(private readonly LazyMaintenance $maintenance) {}

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->maintenance->runBudgeted();
        } catch (Throwable) {
            // Maintenance is opportunistic; correctness never depends on any
            // single run completing (§FR-15).
        }
    }
}
