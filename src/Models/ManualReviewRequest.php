<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Support\GatewayUsers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A human decision queue entry for payments that could not be auto-confirmed
 * (§FR-12): customer reports and ambiguous multi-matches.
 *
 * @property int $id
 * @property int $payment_id
 * @property int|null $incoming_sms_id
 * @property int|null $reported_amount
 * @property \Illuminate\Support\Carbon|null $approximate_paid_at
 * @property string|null $contact_mobile
 * @property string|null $customer_note
 * @property string|null $receipt_path
 * @property int|null $actual_amount
 * @property string|null $internal_note
 * @property string $status
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 */
class ManualReviewRequest extends Model
{
    protected $table = 'cp_manual_review_requests';

    protected $fillable = [
        'payment_id',
        'incoming_sms_id',
        'reported_amount',
        'approximate_paid_at',
        'contact_mobile',
        'customer_note',
        'receipt_path',
        'actual_amount',
        'internal_note',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reported_amount' => 'integer',
            'actual_amount' => 'integer',
            'approximate_paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(GatewayUsers::model(), 'reviewed_by');
    }
}
