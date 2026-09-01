<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Enums;

/**
 * How a payment↔SMS evidence link was established (cp_payment_matches).
 */
enum MatchType: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
