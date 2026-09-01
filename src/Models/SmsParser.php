<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-bank SMS extraction rules (§FR-6). Keyword lists are stored as JSON
 * arrays; regexes are admin-configured and applied defensively.
 *
 * @property int $id
 * @property string $name
 * @property string $bank_name
 * @property string|null $sender_pattern
 * @property string $amount_regex
 * @property string|null $date_regex
 * @property string|null $time_regex
 * @property string|null $transaction_type_regex
 * @property list<string>|null $positive_keywords
 * @property list<string>|null $negative_keywords
 * @property string|null $sample_sms
 * @property bool $is_active
 */
class SmsParser extends Model
{
    protected $table = 'cp_sms_parsers';

    protected $fillable = [
        'name',
        'bank_name',
        'sender_pattern',
        'amount_regex',
        'date_regex',
        'time_regex',
        'transaction_type_regex',
        'positive_keywords',
        'negative_keywords',
        'sample_sms',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'positive_keywords' => 'array',
            'negative_keywords' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function bankCards(): HasMany
    {
        return $this->hasMany(BankCard::class, 'sms_parser_id');
    }
}
