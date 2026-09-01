<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Enums\WebhookEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A domain event emitted once per (payment, event_type) (§FR-13). The exact
 * stored payload_json is what gets POSTed and signed.
 *
 * @property int $id
 * @property string $event_id
 * @property int $application_id
 * @property int $payment_id
 * @property WebhookEventType $event_type
 * @property array<string,mixed> $payload_json
 */
class WebhookEvent extends Model
{
    protected $table = 'cp_webhook_events';

    protected $fillable = [
        'event_id',
        'application_id',
        'payment_id',
        'event_type',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => WebhookEventType::class,
            'payload_json' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_event_id');
    }
}
