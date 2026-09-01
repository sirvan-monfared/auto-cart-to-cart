<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Sms;

use CartBecart\CardPay\Models\SmsParser as SmsParserModel;

/**
 * The subset of a bank's parser rules the parse pipeline (§A3) actually needs.
 *
 * Kept as a plain value object (not the Eloquent model) so {@see SmsParser} is a
 * pure function of its inputs — trivially testable against the spec vectors and
 * free of any database coupling. The sender_pattern gate is enforced by the
 * caller BEFORE the pipeline runs, so it is intentionally absent here.
 */
final readonly class ParserConfig
{
    /**
     * @param  list<string>  $positiveKeywords
     * @param  list<string>  $negativeKeywords
     */
    public function __construct(
        public string $amountRegex,
        public array $positiveKeywords = [],
        public array $negativeKeywords = [],
    ) {}

    public static function fromModel(SmsParserModel $parser): self
    {
        return new self(
            amountRegex: (string) $parser->amount_regex,
            positiveKeywords: $parser->positive_keywords ?? [],
            negativeKeywords: $parser->negative_keywords ?? [],
        );
    }
}
