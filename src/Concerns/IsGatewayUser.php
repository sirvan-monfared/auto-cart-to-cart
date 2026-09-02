<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Concerns;

use CartBecart\CardPay\Contracts\GatewayUser;
use Illuminate\Support\Str;

/**
 * The ONE code change a host application makes: adopt this trait on its User
 * model. It implements the GatewayUser contract from the `role` and
 * `is_active` columns added by the published cardpay user-migration.
 *
 *   class User extends Authenticatable
 *   {
 *       use \CartBecart\CardPay\Concerns\IsGatewayUser;
 *   }
 *
 * PHP 8.2+ allows an abstract method in a trait to declare the interface it
 * satisfies, but the using CLASS must still declare `implements GatewayUser`.
 * The trait supplies the method body; add the interface to your class:
 *
 *   class User extends Authenticatable implements \CartBecart\CardPay\Contracts\GatewayUser
 */
trait IsGatewayUser
{
    public function isActiveAdmin(): bool
    {
        return $this->role === 'admin' && (bool) $this->is_active;
    }

    /**
     * The user's initials (avatar/menu display), as the monolith's User had.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
