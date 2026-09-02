<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Concerns;

use CartBecart\CardPay\Support\GatewayUsers;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For domain models that attribute an action to a host user
 * (reviewed_by / decided_by). Resolves the relation against the CONFIGURED
 * host user model instead of a hardcoded class.
 */
trait BelongsToGatewayUser
{
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(GatewayUsers::model(), 'reviewed_by');
    }
}
