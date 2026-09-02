<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use CartBecart\CardPay\Support\Edition;
use Illuminate\Database\Eloquent\Model;

/**
 * Typed key-value configuration (§14.2). `is_public` marks values that may be
 * exposed to the hosted checkout page (branding, texts).
 *
 * The table is a panel-side convenience: when the `db_settings` feature is off
 * (the lite default) there is no cp_settings table, and every read resolves
 * from config('cardpay.settings') instead. Callers use the same API either
 * way, so checkout branding works in both editions (§16).
 *
 * @property int $id
 * @property string $setting_key
 * @property string|null $setting_value
 * @property string $value_type string|int|bool
 * @property bool $is_public
 */
class Setting extends Model
{
    protected $table = 'cp_settings';

    protected $fillable = [
        'setting_key',
        'setting_value',
        'value_type',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * Read a setting, coerced to its declared type. Returns $default when absent.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (! Edition::enabled('db_settings')) {
            return config('cardpay.settings.'.$key, $default);
        }

        $row = static::query()->where('setting_key', $key)->first();

        return $row?->typedValue() ?? $default;
    }

    /**
     * Upsert a setting with an explicit type and public-exposure flag.
     *
     * A no-op when the store is config-backed: there is nothing to write to,
     * and silently ignoring the write is safer than a hard failure on a code
     * path (seeding, setup) that is not meant to run in that edition anyway.
     */
    public static function put(string $key, mixed $value, string $type = 'string', bool $isPublic = false): void
    {
        if (! Edition::enabled('db_settings')) {
            return;
        }

        static::query()->updateOrCreate(
            ['setting_key' => $key],
            [
                'setting_value' => static::stringifyValue($value, $type),
                'value_type' => $type,
                'is_public' => $isPublic,
            ],
        );
    }

    /**
     * The stored value coerced to its declared PHP type.
     */
    public function typedValue(): mixed
    {
        $raw = $this->setting_value;

        if ($raw === null) {
            return null;
        }

        return match ($this->value_type) {
            'int' => (int) $raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            default => $raw,
        };
    }

    private static function stringifyValue(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
