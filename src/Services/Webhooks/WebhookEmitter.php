<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Webhooks;

use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Models\Payment;

/**
 * Emits a domain event toward the merchant's webhook (§FR-13).
 *
 * "Emission" means durably recording the event (idempotent per payment +
 * event type) so it can be delivered later; the actual HTTP POST is performed
 * out of band by budgeted maintenance (§A9), never on the recognition path.
 *
 * Defined as an interface so the recognition core (matcher, expiry, cancel) can
 * depend on it now while the concrete delivery machinery lands in a later
 * milestone, and so tests can substitute a recording double. Implementations
 * MUST NOT throw in a way that could roll back financial state — a webhook
 * failure never unwinds a confirmed payment (§FR-13).
 */
interface WebhookEmitter
{
    public function emit(Payment $payment, WebhookEventType $event): void;
}
