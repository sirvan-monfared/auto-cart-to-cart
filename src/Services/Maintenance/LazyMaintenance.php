<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Maintenance;

use CartBecart\CardPay\Models\ApiNonce;
use CartBecart\CardPay\Models\DeviceNonce;
use CartBecart\CardPay\Models\IdempotencyKey;
use CartBecart\CardPay\Models\RateLimit;
use CartBecart\CardPay\Services\Payments\PaymentExpiryService;
use CartBecart\CardPay\Services\Payments\TokenAllocator;
use CartBecart\CardPay\Services\Webhooks\WebhookProcessor;

/**
 * The engine that keeps CardPay live without cron or queue workers (§A9 / §FR-15).
 *
 * `runBudgeted()` performs a small, bounded slice of periodic work on each
 * triggering request — hosted-page views, public status polling, and every
 * authenticated API call. Because the slice is capped, it adds only a few
 * indexed queries of latency; because it runs on ordinary traffic, the backlog
 * never grows unbounded. Nothing here is on the money-recognition critical
 * path: expiry uses the same fail-safe conditional transition as the matcher,
 * and webhook HTTP delivery is deferred to a seam (WebhookProcessor).
 *
 * This service is intentionally throw-through: callers that invoke it
 * opportunistically (e.g. in terminable middleware, after the response is
 * flushed) are responsible for guarding it so a maintenance hiccup can never
 * affect a user's request.
 */
final class LazyMaintenance
{
    public function __construct(
        private readonly PaymentExpiryService $expiry,
        private readonly TokenAllocator $allocator,
        private readonly WebhookProcessor $webhooks,
    ) {}

    /**
     * Run one budgeted maintenance slice: expire overdue payments, free
     * cooled-down amount reservations, attempt due webhook deliveries, and purge
     * expired ephemeral rows.
     */
    public function runBudgeted(): void
    {
        $this->expiry->expireDue($this->budget('expire_due', 20));
        $this->allocator->releaseDue($this->budget('release_due', 100));
        $this->webhooks->processDue($this->budget('webhook_process_due', 3));
        $this->purgeExpired();
    }

    /**
     * Delete ephemeral rows whose lifetime has elapsed: anti-replay nonces (§A5),
     * rate-limit buckets (§A7), and spent idempotency keys (§A8). Each is bounded
     * by its own `expires_at`, so only genuinely dead rows are removed. Returns
     * the total number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now = now();

        $deleted = 0;
        $deleted += ApiNonce::query()->where('expires_at', '<=', $now)->delete();
        $deleted += DeviceNonce::query()->where('expires_at', '<=', $now)->delete();
        $deleted += RateLimit::query()->where('expires_at', '<=', $now)->delete();
        $deleted += IdempotencyKey::query()->where('expires_at', '<=', $now)->delete();

        return $deleted;
    }

    private function budget(string $key, int $default): int
    {
        return (int) config("cardpay.maintenance.{$key}", $default);
    }
}
