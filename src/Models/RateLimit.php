<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A fixed-window rate-limit counter bucket (§A7).
 *
 * @property int $id
 * @property string $scope
 * @property string $rate_key
 * @property int $window_start
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $expires_at
 */
class RateLimit extends Model
{
    protected $table = 'cp_rate_limits';

    protected $fillable = [
        'scope',
        'rate_key',
        'window_start',
        'attempts',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'integer',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
