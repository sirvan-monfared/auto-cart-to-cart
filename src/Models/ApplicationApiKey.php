<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Casts\Encrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rotating public/secret credential pair for an application (§FR-3).
 *
 * The secret is encrypted at rest (§SR-1) and additionally fingerprinted
 * (sha256 hex) so a presented secret can be matched without decryption.
 *
 * @property int $id
 * @property int $application_id
 * @property string $public_key
 * @property string|null $secret_encrypted Plaintext when accessed (decrypted by cast)
 * @property string $secret_fingerprint
 * @property string|null $label
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
class ApplicationApiKey extends Model
{
    protected $table = 'cp_application_api_keys';

    protected $fillable = [
        'application_id',
        'public_key',
        'secret_encrypted',
        'secret_fingerprint',
        'label',
        'is_active',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'secret_encrypted',
        'secret_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => Encrypted::class,
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }
}
