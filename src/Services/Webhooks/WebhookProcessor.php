<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Webhooks;

/**
 * Delivers due webhook deliveries over HTTP (§A6 / §FR-13).
 *
 * Defined as an interface so budgeted maintenance (§A9) can depend on it now
 * while the concrete retry-ladder delivery machinery lands in a later milestone,
 * and so the recognition core never links against HTTP delivery directly.
 * Processing MUST NOT throw into the maintenance orchestrator in a way that
 * could affect a triggering request — a webhook failure never alters financial
 * state (§FR-13).
 */
interface WebhookProcessor
{
    /**
     * Attempt up to $limit due deliveries (status pending|failed with
     * next_attempt_at ≤ now), ordered by next_attempt_at. Returns the number
     * of deliveries attempted.
     */
    public function processDue(int $limit): int;
}
