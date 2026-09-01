<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Security;

use CartBecart\CardPay\Enums\ApiErrorCode;

/**
 * The header names and error codes for one HMAC-authenticated surface (§A5).
 *
 * Merchant and device requests share the exact same canonical string and
 * verification algorithm; only their header prefixes and the catalog codes they
 * fail with differ. Bundling those differences here lets a single
 * {@see HmacAuthenticator} serve both without branching.
 */
final class HmacScheme
{
    public function __construct(
        public readonly string $keyHeader,
        public readonly string $timestampHeader,
        public readonly string $nonceHeader,
        public readonly string $signatureHeader,
        public readonly ApiErrorCode $keyError,
        public readonly ApiErrorCode $signatureError,
    ) {}

    /** Merchant API surface — `X-CardPay-*` headers. */
    public static function merchant(): self
    {
        return new self(
            'X-CardPay-Key',
            'X-CardPay-Timestamp',
            'X-CardPay-Nonce',
            'X-CardPay-Signature',
            ApiErrorCode::InvalidApiKey,
            ApiErrorCode::InvalidSignature,
        );
    }

    /** Trusted device surface — `X-Device-*` headers. */
    public static function device(): self
    {
        return new self(
            'X-Device-Key',
            'X-Device-Timestamp',
            'X-Device-Nonce',
            'X-Device-Signature',
            ApiErrorCode::InvalidDeviceKey,
            ApiErrorCode::InvalidDeviceSignature,
        );
    }
}
