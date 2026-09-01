<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A retryable HTTP delivery attempt for a webhook event (§FR-13/§A6).
 *
 * @property int $id
 * @property int $webhook_event_id
 * @property string $url
 * @property int $attempt
 * @property DeliveryStatus $status
 * @property int|null $response_status
 * @property string|null $response_body
 * @property int|null $duration_ms
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $next_attempt_at
 * @property \Illuminate\Support\Carbon|null $last_attempt_at
 */
class WebhookDelivery extends Model
{
    protected $table = 'cp_webhook_deliveries';

    protected $fillable = [
        'webhook_event_id',
        'url',
        'attempt',
        'status',
        'response_status',
        'response_body',
        'duration_ms',
        'error_message',
        'next_attempt_at',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempt' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }
}
