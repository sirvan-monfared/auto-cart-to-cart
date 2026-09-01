<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Merchant-API anti-replay nonce, scoped per application (§A5/§SR-4).
 *
 * @property int $id
 * @property int $application_id
 * @property string $nonce
 * @property \Illuminate\Support\Carbon $expires_at
 */
class ApiNonce extends Model
{
    protected $table = 'cp_api_nonces';

    protected $fillable = [
        'application_id',
        'nonce',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
