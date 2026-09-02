<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Provisioning;

use SensitiveParameter;

/**
 * A freshly minted API key pair, in the only moment its secret exists in
 * plaintext (§SR-1). It is returned to the caller for a one-time reveal — the
 * installer prints it, the admin API returns it once — and is never stored,
 * logged, or recoverable afterwards. Losing it means rotating.
 */
final readonly class GatewayCredentials
{
    public function __construct(
        public string $publicKey,
        #[SensitiveParameter] public string $secret,
    ) {}

    /**
     * @return array{public_key: string, secret: string}
     */
    public function toArray(): array
    {
        return [
            'public_key' => $this->publicKey,
            'secret' => $this->secret,
        ];
    }
}
