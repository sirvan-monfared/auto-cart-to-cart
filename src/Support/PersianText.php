<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Support;

/**
 * Persian / Arabic text normalization for SMS parsing (§A2).
 *
 * Bank SMS bodies arrive with Persian (۰-۹) or Arabic-Indic (٠-٩) digits and
 * Arabic glyph variants. Normalizing to a canonical ASCII form makes the
 * per-bank regexes deterministic and lets us parse the deposit amount reliably.
 */
final class PersianText
{
    /** Persian digits ۰۱۲۳۴۵۶۷۸۹ (U+06F0–U+06F9) → 0-9. */
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** Arabic-Indic digits ٠١٢٣٤٥٦٧٨٩ (U+0660–U+0669) → 0-9. */
    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /** Arabic glyph variants folded to their Persian equivalents. */
    private const CHAR_MAP = [
        'ي' => 'ی', // Arabic yeh    → Persian yeh
        'ى' => 'ی', // Alef maksura  → Persian yeh
        'ك' => 'ک', // Arabic kaf    → Persian keheh
        'ة' => 'ه', // Teh marbuta   → heh
        'ۀ' => 'ه', // Heh with yeh  → heh
    ];

    /**
     * Thousands / grouping separators stripped before integer parsing (§A2):
     * ASCII space, comma, Arabic comma (U+060C), Arabic thousands sep (U+066C),
     * plus non-breaking / narrow no-break spaces occasionally injected by banks.
     *
     * The period is intentionally NOT a separator: IRR has no decimal minor unit
     * and the reference amount regex never captures one, so treating "." as a
     * grouping mark could silently merge a decimal fraction into the amount.
     */
    private const SEPARATORS = [' ', ',', '،', '٬', "\u{00A0}", "\u{202F}"];

    /**
     * Map every Persian and Arabic-Indic digit to its ASCII counterpart.
     */
    public static function normalizeDigits(string $value): string
    {
        $value = str_replace(self::PERSIAN_DIGITS, range(0, 9), $value);

        return str_replace(self::ARABIC_DIGITS, range(0, 9), $value);
    }

    /**
     * Fold Arabic glyph variants to their canonical Persian forms.
     */
    public static function normalizeChars(string $value): string
    {
        return strtr($value, self::CHAR_MAP);
    }

    /**
     * Full normalization applied to an SMS body before parsing: digits first,
     * then glyphs.
     */
    public static function normalize(string $value): string
    {
        return self::normalizeChars(self::normalizeDigits($value));
    }

    /**
     * Convert a captured amount fragment to a positive integer of minor units.
     *
     * Returns null when the fragment is unparsable or non-positive, so callers
     * can treat "no usable amount" uniformly (never guess with money).
     */
    public static function toInteger(string $amountText): ?int
    {
        $normalized = self::normalizeDigits($amountText);
        $stripped = str_replace(self::SEPARATORS, '', $normalized);
        $stripped = trim($stripped);

        // After normalization + separator stripping, a valid amount is pure ASCII
        // digits. Anything else (letters, symbols, decimals) is rejected outright.
        if ($stripped === '' || ! ctype_digit($stripped)) {
            return null;
        }

        // Guard against overflow from absurdly long digit runs before casting.
        if (strlen($stripped) > 18) {
            return null;
        }

        $amount = (int) $stripped;

        return $amount > 0 ? $amount : null;
    }
}
