<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Enums;

/**
 * Payment lifecycle states (§9).
 *
 * pending → paid | expired | canceled | manual_review
 * manual_review → paid | rejected
 * expired → manual_review | paid
 * terminal: paid, canceled, rejected
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case ManualReview = 'manual_review';
    case Rejected = 'rejected';

    /**
     * Terminal states can never transition to another state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Canceled, self::Rejected], true);
    }
    /**
     * Human-readable label, translatable via lang files.
     */
    public function label(): string
    {
        return __(':value', ['value' => $this->value]);
    }

}
