<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Exceptions;

use RuntimeException;

/**
 * Raised when every token slot for a bank card is currently reserved (§A1).
 * Maps to HTTP 409 `token_pool_exhausted`.
 */
final class TokenPoolExhaustedException extends RuntimeException
{
    public function __construct(public readonly int $bankCardId)
    {
        parent::__construct("Token pool exhausted for bank card {$bankCardId}.");
    }
}
