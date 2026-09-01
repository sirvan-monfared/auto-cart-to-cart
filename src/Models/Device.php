<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Casts\Encrypted;
use CartBecart\CardPay\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A trusted relay phone / shortcut that forwards bank deposit SMS (§FR-5).
 *
 * The device secret is encrypted at rest (§SR-1) and fingerprinted for the
 * constant-time shortcut-mode check.
 *
 * @property int $id
 * @property string $name
 * @property DevicePlatform $platform
 * @property string $device_key
 * @property string|null $device_secret_encrypted Plaintext when accessed (decrypted by cast)
 * @property string $secret_fingerprint
 * @property int $bank_card_id
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 * @property string|null $last_ip
 * @property int $sms_count
 * @property Carbon|null $revoked_at
 */
class Device extends Model
{
    protected $table = 'cp_devices';

    protected $fillable = [
        'name',
        'platform',
        'device_key',
        'device_secret_encrypted',
        'secret_fingerprint',
        'bank_card_id',
        'is_active',
        'last_seen_at',
        'last_ip',
        'sms_count',
        'revoked_at',
    ];

    protected $hidden = [
        'device_secret_encrypted',
        'secret_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'device_secret_encrypted' => Encrypted::class,
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'sms_count' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * A device may relay SMS only while active and not revoked.
     */
    public function isUsable(): bool
    {
        return $this->is_active && $this->revoked_at === null;
    }

    public function bankCard(): BelongsTo
    {
        return $this->belongsTo(BankCard::class, 'bank_card_id');
    }

    public function incomingSms(): HasMany
    {
        return $this->hasMany(IncomingSms::class, 'device_id');
    }
}
