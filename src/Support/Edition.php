<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Support;

use InvalidArgumentException;

/**
 * Edition and feature resolution (§16).
 *
 * `full` is the bundled product: the Blade/Livewire panel, the browser setup
 * wizard, and the multi-application merchant surface. `lite` is the
 * single-merchant, API-only distribution — the SAME payment engine and the
 * same core schema, driven by the host application's own admin through the
 * Admin JSON API instead of a panel this package owns.
 *
 * An edition only ever picks DEFAULTS. An explicit boolean in
 * config('cardpay.features.*') always wins, so a deployment can run lite with
 * the audit trail on, or full with the bundled panel off, without forking.
 */
final class Edition
{
    public const FULL = 'full';

    public const LITE = 'lite';

    /**
     * Per-edition defaults. Every feature must appear here: an unknown name is
     * a programming error rather than an implicit "off", so a typo in a gate
     * surfaces immediately instead of silently disabling a surface.
     *
     * @var array<string, array<string, bool>>
     */
    private const DEFAULTS = [
        // Bundled admin panel: Blade views, panel routes, Livewire/Flux pages.
        'panel' => [self::FULL => true, self::LITE => false],

        // Browser-based install wizard (/{path}/setup).
        'setup_wizard' => [self::FULL => true, self::LITE => false],

        // cp_audit_logs writes. Lite hosts usually have their own activity log.
        'audit' => [self::FULL => true, self::LITE => false],

        // cp_settings table. When off, settings resolve from config('cardpay.settings').
        'db_settings' => [self::FULL => true, self::LITE => false],

        // Multi-application CRUD. Lite runs exactly one application, managed
        // as a single "gateway" resource rather than a collection.
        'applications_admin' => [self::FULL => true, self::LITE => false],

        // Admin JSON API for a host-owned admin UI.
        'admin_api' => [self::FULL => true, self::LITE => true],

        // Hosted checkout page + public status polling.
        'checkout' => [self::FULL => true, self::LITE => true],

        // HMAC merchant API. Off means payments are created in-process only,
        // through the CardPay facade.
        'merchant_api' => [self::FULL => true, self::LITE => true],

        // Device SMS relay endpoints — the source of automatic matching.
        'device_api' => [self::FULL => true, self::LITE => true],
    ];

    /** The configured edition, falling back to full for an unknown value. */
    public static function current(): string
    {
        $edition = strtolower(trim((string) config('cardpay.edition', self::FULL)));

        return isset(self::DEFAULTS['panel'][$edition]) ? $edition : self::FULL;
    }

    public static function isLite(): bool
    {
        return self::current() === self::LITE;
    }

    public static function isFull(): bool
    {
        return self::current() === self::FULL;
    }

    /**
     * Whether a feature is active: explicit config override first, else the
     * current edition's default.
     *
     * @throws InvalidArgumentException when the feature name is not declared
     */
    public static function enabled(string $feature): bool
    {
        if (! array_key_exists($feature, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown CardPay feature [{$feature}].");
        }

        $override = config('cardpay.features.'.$feature);

        if (is_bool($override)) {
            return $override;
        }

        return self::DEFAULTS[$feature][self::current()];
    }

    /**
     * The resolved feature map — useful for a host admin to render what this
     * install actually exposes.
     *
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $resolved = [];

        foreach (array_keys(self::DEFAULTS) as $feature) {
            $resolved[$feature] = self::enabled($feature);
        }

        return $resolved;
    }

    /** @return list<string> */
    public static function features(): array
    {
        return array_keys(self::DEFAULTS);
    }
}
