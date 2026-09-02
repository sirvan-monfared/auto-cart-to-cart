<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Create-replay ledger for idempotent payment creation (§A8).
 *
 * @property int $id
 * @property int $application_id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int|null $payment_id
 * @property array<string,mixed>|null $response_json
 * @property Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    protected $table = 'cp_idempotency_keys';

    protected $fillable = [
        'application_id',
        'idempotency_key',
        'request_hash',
        'payment_id',
        'response_json',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_json' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
