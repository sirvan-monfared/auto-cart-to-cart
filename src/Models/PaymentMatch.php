<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Enums\MatchType;
use CartBecart\CardPay\Support\GatewayUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence linking a payment to the SMS (or admin decision) that confirmed it
 * (§FR-11/§FR-12). One row per (payment, sms) pair.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $incoming_sms_id
 * @property MatchType $match_type
 * @property string $confidence
 * @property int|null $decided_by
 * @property string|null $notes
 */
class PaymentMatch extends Model
{
    protected $table = 'cp_payment_matches';

    protected $fillable = [
        'payment_id',
        'incoming_sms_id',
        'match_type',
        'confidence',
        'decided_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'match_type' => MatchType::class,
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function incomingSms(): BelongsTo
    {
        return $this->belongsTo(IncomingSms::class, 'incoming_sms_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(GatewayUsers::model(), 'decided_by');
    }
}
