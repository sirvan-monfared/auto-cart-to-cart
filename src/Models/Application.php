<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A merchant site that integrates with the gateway (§FR-3).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $public_key
 * @property string|null $webhook_url
 * @property string|null $callback_url
 * @property string|null $allowed_domains
 * @property bool $is_active
 * @property int $token_digits
 * @property int $payment_expiration_minutes
 * @property int|null $default_bank_card_id
 */
class Application extends Model
{
    use HasFactory;

    protected $table = 'cp_applications';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'public_key',
        'webhook_url',
        'callback_url',
        'allowed_domains',
        'is_active',
        'token_digits',
        'payment_expiration_minutes',
        'default_bank_card_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'token_digits' => 'integer',
            'payment_expiration_minutes' => 'integer',
        ];
    }

    /**
     * The allow-list of hosts permitted for return_url / callback_url (§SR-12),
     * one host per line. An empty list means unrestricted.
     *
     * @return list<string>
     */
    public function allowedDomainList(): array
    {
        if (blank($this->allowed_domains)) {
            return [];
        }

        return collect(preg_split('/[\r\n,]+/', $this->allowed_domains))
            ->map(fn (string $host): string => strtolower(trim($host)))
            ->filter()
            ->values()
            ->all();
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApplicationApiKey::class, 'application_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'application_id');
    }

    public function defaultBankCard(): BelongsTo
    {
        return $this->belongsTo(BankCard::class, 'default_bank_card_id');
    }
}
