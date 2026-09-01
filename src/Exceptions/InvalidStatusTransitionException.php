<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Exceptions;

use CartBecart\CardPay\Enums\PaymentStatus;
use RuntimeException;

/**
 * Raised when a payment status transition is not permitted by the authoritative
 * map (§9.2). Maps to HTTP 409 `invalid_status_transition`.
 */
final class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly PaymentStatus $from,
        public readonly PaymentStatus $to,
    ) {
        parent::__construct("Illegal payment transition {$from->value} → {$to->value}.");
    }
}
