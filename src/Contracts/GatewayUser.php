<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Contracts;

/**
 * The contract the HOST application's user model must satisfy for CardPay.
 *
 * The package never assumes a specific user class — the host binds its own
 * model via config('cardpay.user.model') and adopts the IsGatewayUser trait
 * (which implements this interface from the role/is_active columns the
 * cardpay user-migration adds). AdminAuth, Fortify authentication, review
 * attribution and the setup wizard all type-check against this interface.
 */
interface GatewayUser
{
    /**
     * An admin who may access the panel: role=admin and not deactivated.
     */
    public function isActiveAdmin(): bool;

    /**
     * Primary key value — used for audit actor ids and reviewed_by/decided_by.
     * Untyped to stay compatible with Eloquent Model::getKey().
     */
    #[\ReturnTypeWillChange]
    public function getKey();
}
