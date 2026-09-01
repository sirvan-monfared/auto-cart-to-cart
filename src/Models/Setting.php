<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Typed key-value configuration (§14.2). `is_public` marks values that may be
 * exposed to the hosted checkout page (branding, texts).
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
        $row = static::query()->where('setting_key', $key)->first();

        return $row?->typedValue() ?? $default;
    }

    /**
     * Upsert a setting with an explicit type and public-exposure flag.
     */
    public static function put(string $key, mixed $value, string $type = 'string', bool $isPublic = false): void
    {
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
