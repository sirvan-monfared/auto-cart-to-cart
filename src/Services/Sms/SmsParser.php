<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Sms;

use Carbon\CarbonInterface;
use CartBecart\CardPay\Support\PersianText;

/**
 * The ordered, short-circuiting SMS parse pipeline (§A3).
 *
 * A bank deposit SMS is the *only* proof of payment in this gateway, so parsing
 * is deliberately conservative and every branch is explicit:
 *
 *   1. normalize digits + glyphs to a canonical ASCII form;
 *   2. if any NEGATIVE (withdrawal) keyword appears → ignore it;
 *   3. if the parser defines POSITIVE (deposit) keywords and none appear → fail;
 *   4. run the admin-configured amount regex (defensively — a bad pattern is a
 *      parse failure, never a fatal);
 *   5. convert the captured fragment to a positive integer, or fail.
 *
 * Only a clean pass returns a credit with an amount. There is no fallthrough
 * that could invent an amount, which is what keeps the recognition path free of
 * the "confirmed the wrong thing" class of bug.
 *
 * The pipeline is pure: identical inputs always yield an identical result.
 */
final class SmsParser
{
    public function parse(string $raw, ParserConfig $config, CarbonInterface $receivedAt): SmsParseResult
    {
        $text = PersianText::normalize($raw);

        // (2) A withdrawal/debit notice is not a customer payment — ignore it.
        if ($this->containsAny($text, $config->negativeKeywords)) {
            return SmsParseResult::ignored(SmsParseResult::REASON_NEGATIVE);
        }

        // (3) When deposit keywords are configured, at least one must be present.
        $positives = $this->cleanKeywords($config->positiveKeywords);
        if ($positives !== [] && ! $this->containsAny($text, $positives, alreadyClean: true)) {
            return SmsParseResult::failed(SmsParseResult::REASON_NO_POSITIVE);
        }

        // (4) Extract the amount fragment via the per-bank regex.
        $capture = $this->matchAmount($config->amountRegex, $text);
        if ($capture === null) {
            return SmsParseResult::failed(SmsParseResult::REASON_NO_AMOUNT);
        }

        // (5) Parse the fragment into a positive integer of minor units.
        $amount = PersianText::toInteger($capture);
        if ($amount === null) {
            return SmsParseResult::failed(SmsParseResult::REASON_INVALID_AMOUNT);
        }

        return SmsParseResult::ok($amount, $receivedAt);
    }

    /**
     * Whether any keyword appears as a substring of the already-normalized text.
     * Keywords are normalized the same way (so an admin-entered Arabic glyph
     * still matches) and blanks are dropped (an empty keyword must not match all).
     *
     * @param  list<string>  $keywords
     */
    private function containsAny(string $text, array $keywords, bool $alreadyClean = false): bool
    {
        $keywords = $alreadyClean ? $keywords : $this->cleanKeywords($keywords);

        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize, trim, and drop blank/duplicate keywords.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function cleanKeywords(array $keywords): array
    {
        $clean = [];

        foreach ($keywords as $keyword) {
            $normalized = trim(PersianText::normalize((string) $keyword));

            if ($normalized !== '' && ! in_array($normalized, $clean, true)) {
                $clean[] = $normalized;
            }
        }

        return $clean;
    }

    /**
     * Apply the admin-configured regex and return the amount fragment, preferring
     * the named group `amount`, then group 1, then the whole match. Returns null
     * on no match OR on an invalid pattern — the latter is suppressed to a parse
     * failure so a mis-typed regex can never crash the ingestion request (§A3).
     */
    private function matchAmount(string $regex, string $text): ?string
    {
        // @ suppresses the warning an invalid pattern would emit; preg_match then
        // returns false, which we treat exactly like "no match".
        $matched = @preg_match($regex, $text, $groups);

        if ($matched !== 1) {
            return null;
        }

        if (isset($groups['amount']) && $groups['amount'] !== '') {
            return $groups['amount'];
        }

        if (isset($groups[1]) && $groups[1] !== '') {
            return $groups[1];
        }

        return ($groups[0] ?? '') !== '' ? $groups[0] : null;
    }
}
