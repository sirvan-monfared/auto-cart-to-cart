<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tamper-evident audit trail entry (§SR-14).
 *
 * @property int $id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property array<string,mixed>|null $old_values
 * @property array<string,mixed>|null $new_values
 * @property string|null $ip
 * @property string|null $user_agent
 */
class AuditLog extends Model
{
    protected $table = 'cp_audit_logs';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}
