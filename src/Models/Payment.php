<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An order awaiting card-to-card transfer confirmation — the central financial
 * entity (§FR-7, §9). Its `status` funnels through the PaymentStateMachine;
 * money fields are integer minor units.
 *
 * @property int $id
 * @property string $public_id
 * @property int $application_id
 * @property int $bank_card_id
 * @property string $driver
 * @property string|null $external_order_id
 * @property int $original_amount
 * @property int $token
 * @property int $payable_amount
 * @property string $currency
 * @property string|null $description
 * @property string|null $customer_name
 * @property string|null $customer_mobile
 * @property string|null $customer_reference
 * @property PaymentStatus $status
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $canceled_at
 * @property int|null $matched_sms_id
 * @property string|null $return_url
 * @property string|null $callback_url
 * @property array<string,mixed>|null $metadata_json
 * @property Carbon|null $created_at
 */
class Payment extends Model
{
    use HasFactory;

    protected $table = 'cp_payments';

    protected $fillable = [
        'public_id',
        'application_id',
        'bank_card_id',
        'driver',
        'external_order_id',
        'original_amount',
        'token',
        'payable_amount',
        'currency',
        'description',
        'customer_name',
        'customer_mobile',
        'customer_reference',
        'status',
        'expires_at',
        'paid_at',
        'canceled_at',
        'matched_sms_id',
        'return_url',
        'callback_url',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'integer',
            'token' => 'integer',
            'payable_amount' => 'integer',
            'status' => PaymentStatus::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'canceled_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    public function bankCard(): BelongsTo
    {
        return $this->belongsTo(BankCard::class, 'bank_card_id');
    }

    public function matchedSms(): BelongsTo
    {
        return $this->belongsTo(IncomingSms::class, 'matched_sms_id');
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(PaymentTokenReservation::class, 'payment_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(PaymentMatch::class, 'payment_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ManualReviewRequest::class, 'payment_id');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class, 'payment_id');
    }
}
