<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Enums;

/**
 * Domain events emitted to merchant webhooks (§FR-13).
 */
enum WebhookEventType: string
{
    case Created = 'payment.created';
    case Paid = 'payment.paid';
    case Canceled = 'payment.canceled';
    case Expired = 'payment.expired';
    case ManualReview = 'payment.manual_review';
    case Rejected = 'payment.rejected';

    /**
     * Human-readable label, translatable via lang files.
     */
    public function label(): string
    {
        return __(':value', ['value' => $this->value]);
    }
}
