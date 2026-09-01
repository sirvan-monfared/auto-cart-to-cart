<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Enums\ParseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored bank SMS relayed by a device (§FR-9). Carries the parse and match
 * outcomes for the audit trail and the unmatched explorer.
 *
 * @property int $id
 * @property int $device_id
 * @property int $bank_card_id
 * @property string $message_id
 * @property string|null $sender
 * @property string $raw_sms
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon $server_received_at
 * @property string|null $source_ip
 * @property ParseStatus $parse_status
 * @property int|null $parsed_amount
 * @property \Illuminate\Support\Carbon|null $parsed_transaction_at
 * @property string|null $parse_error
 * @property MatchStatus $match_status
 * @property int|null $matched_payment_id
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class IncomingSms extends Model
{
    protected $table = 'cp_incoming_sms';

    protected $fillable = [
        'device_id',
        'bank_card_id',
        'message_id',
        'sender',
        'raw_sms',
        'received_at',
        'server_received_at',
        'source_ip',
        'parse_status',
        'parsed_amount',
        'parsed_transaction_at',
        'parse_error',
        'match_status',
        'matched_payment_id',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'server_received_at' => 'datetime',
            'parsed_transaction_at' => 'datetime',
            'used_at' => 'datetime',
            'parse_status' => ParseStatus::class,
            'match_status' => MatchStatus::class,
            'parsed_amount' => 'integer',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function bankCard(): BelongsTo
    {
        return $this->belongsTo(BankCard::class, 'bank_card_id');
    }

    public function matchedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'matched_payment_id');
    }
}
