<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Device anti-replay nonce, scoped per device (§A5/§SR-4).
 *
 * @property int $id
 * @property int $device_id
 * @property string $nonce
 * @property \Illuminate\Support\Carbon $expires_at
 */
class DeviceNonce extends Model
{
    protected $table = 'cp_device_nonces';

    protected $fillable = [
        'device_id',
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
