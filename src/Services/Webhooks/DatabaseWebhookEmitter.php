<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Webhooks;

use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Records domain events durably and idempotently (§FR-13).
 *
 * One event row per (payment, event_type) — enforced by the unique index, so a
 * retry of the same recognition step can never produce a duplicate webhook.
 * The stored payload_json is the exact body that later gets signed and POSTed;
 * the HTTP delivery itself is performed by budgeted maintenance, out of band.
 */
final class DatabaseWebhookEmitter implements WebhookEmitter
{
    public function emit(Payment $payment, WebhookEventType $event): void
    {
        try {
            WebhookEvent::query()->create([
                'event_id' => 'evt_'.Str::lower((string) Str::ulid()),
                'application_id' => $payment->application_id,
                'payment_id' => $payment->id,
                'event_type' => $event,
                'payload_json' => $this->payload($payment, $event),
            ]);
        } catch (QueryException $e) {
            // The (payment_id, event_type) unique index already holds this event:
            // emission is one-shot, so a second attempt is a deliberate no-op.
            if ((string) $e->getCode() === '23000') {
                return;
            }

            throw $e;
        }
    }

    /**
     * The canonical event body. Amounts are integer minor units; timestamps are
     * ISO-8601 UTC; the opaque public_id is exposed, never the numeric id.
     *
     * @return array<string, mixed>
     */
    private function payload(Payment $payment, WebhookEventType $event): array
    {
        return [
            'event' => $event->value,
            'payment_id' => $payment->public_id,
            'external_order_id' => $payment->external_order_id,
            'status' => $payment->status->value,
            'original_amount' => $payment->original_amount,
            'token' => $payment->token,
            'payable_amount' => $payment->payable_amount,
            'currency' => $payment->currency,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
        ];
    }
}
