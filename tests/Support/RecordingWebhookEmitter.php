<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Tests\Support;

use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;

/**
 * A WebhookEmitter double that records each event it is asked to emit, so tests
 * can assert WHICH domain events a service raised (and in what order) without
 * touching the real webhook_events table or any delivery machinery.
 *
 * It deliberately never throws — mirroring the interface contract that emission
 * must never unwind a committed payment — so a test that binds it observes the
 * service's own success/failure behaviour, not the emitter's.
 */
final class RecordingWebhookEmitter implements WebhookEmitter
{
    /** @var list<array{payment_id: int, event: WebhookEventType}> */
    public array $emitted = [];

    public function emit(Payment $payment, WebhookEventType $event): void
    {
        $this->emitted[] = ['payment_id' => $payment->id, 'event' => $event];
    }

    /**
     * The emitted events, in emission order.
     *
     * @return list<WebhookEventType>
     */
    public function events(): array
    {
        return array_map(fn (array $entry): WebhookEventType => $entry['event'], $this->emitted);
    }
}
