<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Webhooks;

/**
 * No-op processor used until the HTTP delivery machinery (§A6) lands in M7.
 *
 * Budgeted maintenance calls `processDue()` on every triggered request; binding
 * this implementation keeps that contract in place while guaranteeing the
 * recognition path performs no outbound HTTP. It is swapped for the real
 * HTTP-delivering processor once that milestone is built.
 */
final class NullWebhookProcessor implements WebhookProcessor
{
    public function processDue(int $limit): int
    {
        return 0;
    }
}
