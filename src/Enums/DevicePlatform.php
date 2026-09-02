<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Enums;

/**
 * Trusted relay device platforms (§FR-5).
 */
enum DevicePlatform: string
{
    case Android = 'android';
    case IosShortcut = 'ios-shortcut';

    /**
     * Human-readable label, translatable via lang files.
     */
    public function label(): string
    {
        return __(':value', ['value' => $this->value]);
    }
}
