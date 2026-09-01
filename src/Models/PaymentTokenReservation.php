<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An active amount lock that makes each open payment's payable_amount unique
 * per card (§A1). `active_key` is 1 while the amount is reserved and NULL once
 * released; the partial-uniqueness trick lives in the UNIQUE index
 * (bank_card_id, payable_amount, active_key).
 *
 * @property int $id
 * @property int|null $payment_id
 * @property int $bank_card_id
 * @property int $payable_amount
 * @property int $token
 * @property bool|null $active_key
 * @property \Illuminate\Support\Carbon|null $release_at
 */
class PaymentTokenReservation extends Model
{
    protected $table = 'cp_payment_token_reservations';

    protected $fillable = [
        'payment_id',
        'bank_card_id',
        'payable_amount',
        'token',
        'active_key',
        'release_at',
    ];

    protected function casts(): array
    {
        return [
            'payable_amount' => 'integer',
            'token' => 'integer',
            'active_key' => 'boolean',
            'release_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function bankCard(): BelongsTo
    {
        return $this->belongsTo(BankCard::class, 'bank_card_id');
    }
}
