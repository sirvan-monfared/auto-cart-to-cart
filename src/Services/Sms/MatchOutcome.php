<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Sms;

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Models\Payment;

/**
 * The result of running one parsed SMS through the matching engine (§FR-11),
 * mirroring the SMS's resulting match_status plus the affected payment(s).
 */
final readonly class MatchOutcome
{
    /**
     * @param  list<Payment>  $manualReviewPayments  candidates escalated to review (ambiguous case)
     */
    private function __construct(
        public MatchStatus $status,
        public ?Payment $payment = null,
        public array $manualReviewPayments = [],
    ) {}

    /** Exactly one candidate, confirmed paid. */
    public static function matched(Payment $payment): self
    {
        return new self(MatchStatus::Matched, $payment);
    }

    /**
     * More than one candidate — all escalated, none paid.
     *
     * @param  list<Payment>  $manualReviewPayments
     */
    public static function ambiguous(array $manualReviewPayments): self
    {
        return new self(MatchStatus::Ambiguous, null, $manualReviewPayments);
    }

    /** No candidate (late/foreign deposit). */
    public static function unmatched(): self
    {
        return new self(MatchStatus::Unmatched);
    }

    /** A single candidate whose confirmation lost a concurrent race. */
    public static function manualReview(): self
    {
        return new self(MatchStatus::ManualReview);
    }

    public function isMatched(): bool
    {
        return $this->status === MatchStatus::Matched;
    }
}
