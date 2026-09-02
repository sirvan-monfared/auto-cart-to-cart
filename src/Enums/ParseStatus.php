<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Enums;

/**
 * Result of running an incoming SMS through the parse pipeline (§FR-10).
 */
enum ParseStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Failed = 'failed';
    case Ignored = 'ignored';

    /**
     * Human-readable label, translatable via lang files.
     */
    public function label(): string
    {
        return __(':value', ['value' => $this->value]);
    }
}
