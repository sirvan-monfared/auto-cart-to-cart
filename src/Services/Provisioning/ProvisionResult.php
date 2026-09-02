<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Provisioning;

use CartBecart\CardPay\Models\Application;

/**
 * Outcome of provisioning the single gateway application.
 *
 * `credentials` is non-null ONLY when the application was created by this
 * call: re-running the installer must never rotate a live secret behind the
 * merchant's back, so an already-provisioned gateway reports created=false
 * and reveals nothing.
 */
final readonly class ProvisionResult
{
    public function __construct(
        public Application $application,
        public bool $created,
        public ?GatewayCredentials $credentials = null,
    ) {}
}
