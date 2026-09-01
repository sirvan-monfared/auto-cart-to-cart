<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Enums;

/**
 * Webhook delivery states (§FR-13).
 *
 * pending → delivered | failed → exhausted
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
    /**
     * Human-readable label, translatable via lang files.
     */
    public function label(): string
    {
        return __(':value', ['value' => $this->value]);
    }

}
