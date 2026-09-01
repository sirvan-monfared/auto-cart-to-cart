<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Payments;

/**
 * The outcome of a create/cancel operation: the §FR-7 response `data` payload
 * plus whether the payment was freshly created (HTTP 201) or is an idempotent
 * replay of an earlier create (HTTP 200). The `idempotent_replay` flag inside
 * {@see $data} always agrees with {@see $created}.
 */
final class PaymentResult
{
    /**
     * @param  array<string, mixed>  $data  The response envelope's `data` object.
     * @param  bool  $created  true → 201 (new), false → 200 (idempotent replay).
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $created,
    ) {}

    public function status(): int
    {
        return $this->created ? 201 : 200;
    }
}
