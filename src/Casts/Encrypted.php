<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Casts;

use CartBecart\CardPay\Services\Security\Crypto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Transparently encrypts an attribute at rest using the AES-256-GCM envelope
 * (§SR-1). Applied to bank card numbers, IBANs, application secrets, and device
 * secrets so plaintext is never written to the database.
 *
 * Usage: protected function casts(): array { return ['secret' => Encrypted::class]; }
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class Encrypted implements CastsAttributes
{
    /**
     * Decrypt on read. A null/empty stored value yields null.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return app(Crypto::class)->decrypt($value);
    }

    /**
     * Encrypt on write. Null passes through so nullable columns stay null.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(Crypto::class)->encrypt((string) $value);
    }
}
