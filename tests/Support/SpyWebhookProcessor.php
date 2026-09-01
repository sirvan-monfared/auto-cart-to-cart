<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Tests\Support;

use CartBecart\CardPay\Services\Webhooks\WebhookProcessor;

/**
 * Records how budgeted maintenance drives webhook processing, so a test can
 * assert the processor was invoked with the configured budget without needing
 * the real HTTP-delivery machinery.
 */
class SpyWebhookProcessor implements WebhookProcessor
{
    public int $calls = 0;

    public ?int $lastLimit = null;

    public function processDue(int $limit): int
    {
        $this->calls++;
        $this->lastLimit = $limit;

        return 0;
    }
}
