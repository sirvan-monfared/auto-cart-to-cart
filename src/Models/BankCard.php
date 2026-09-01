<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Casts\Encrypted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A destination bank card that customers transfer money to (§FR-4).
 *
 * The full card number and IBAN are encrypted at rest (§SR-1); only the last
 * four digits are stored in the clear for display.
 *
 * @property int $id
 * @property string $title
 * @property string $bank_name
 * @property string|null $card_number_encrypted Plaintext when accessed (decrypted by cast)
 * @property string $card_number_last_four
 * @property string $card_holder_name
 * @property string|null $iban_encrypted Plaintext when accessed (decrypted by cast)
 * @property string|null $description
 * @property int|null $sms_parser_id
 * @property bool $is_active
 */
class BankCard extends Model
{
    use HasFactory;

    protected $table = 'cp_bank_cards';

    protected $fillable = [
        'title',
        'bank_name',
        'card_number_encrypted',
        'card_number_last_four',
        'card_holder_name',
        'iban_encrypted',
        'description',
        'sms_parser_id',
        'is_active',
    ];

    protected $hidden = [
        'card_number_encrypted',
        'iban_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'card_number_encrypted' => Encrypted::class,
            'iban_encrypted' => Encrypted::class,
            'is_active' => 'boolean',
        ];
    }

    public function smsParser(): BelongsTo
    {
        return $this->belongsTo(SmsParser::class, 'sms_parser_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bank_card_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'bank_card_id');
    }

    public function incomingSms(): HasMany
    {
        return $this->hasMany(IncomingSms::class, 'bank_card_id');
    }
}
