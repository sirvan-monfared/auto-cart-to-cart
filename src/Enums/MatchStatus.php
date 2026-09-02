<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Enums;

/**
 * Outcome of matching a parsed SMS against pending payments (§FR-11).
 */
enum MatchStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Ambiguous = 'ambiguous';
    case ManualReview = 'manual_review';

    /**
     * Human-readable label, translatable via lang files.
     */
    public function label(): string
    {
        return __(':value', ['value' => $this->value]);
    }
}
