<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Sms;

use CartBecart\CardPay\Enums\ParseStatus;
use Carbon\CarbonInterface;

/**
 * Immutable outcome of running one SMS through the parse pipeline (§A3).
 *
 * A "credit" result with {@see $amount} set is the only shape that can go on to
 * matching; everything else is either ignored (a debit/withdrawal we don't care
 * about) or a recorded parse failure. Money is never guessed — an unparsable
 * amount yields a failure, not a zero.
 */
final readonly class SmsParseResult
{
    public const string TYPE_CREDIT = 'credit';

    public const string TYPE_DEBIT = 'debit';

    public const string TYPE_UNKNOWN = 'unknown';

    public const string REASON_NEGATIVE = 'negative_transaction';

    public const string REASON_NO_POSITIVE = 'positive_keyword_not_found';

    public const string REASON_NO_AMOUNT = 'amount_not_found';

    public const string REASON_INVALID_AMOUNT = 'invalid_amount';

    private function __construct(
        public ParseStatus $status,
        public ?int $amount,
        public string $transactionType,
        public ?string $failureReason,
        public ?CarbonInterface $transactionAt,
    ) {}

    /** A successfully parsed customer deposit. */
    public static function ok(int $amount, CarbonInterface $transactionAt): self
    {
        return new self(ParseStatus::Parsed, $amount, self::TYPE_CREDIT, null, $transactionAt);
    }

    /** A recognised debit/withdrawal — deliberately ignored, never matched. */
    public static function ignored(string $reason): self
    {
        return new self(ParseStatus::Ignored, null, self::TYPE_DEBIT, $reason, null);
    }

    /** A parse failure (no deposit keyword, no amount, or an unparsable amount). */
    public static function failed(string $reason, string $type = self::TYPE_UNKNOWN): self
    {
        return new self(ParseStatus::Failed, null, $type, $reason, null);
    }

    public function isOk(): bool
    {
        return $this->status === ParseStatus::Parsed;
    }
}
