<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\ParseStatus;
use CartBecart\CardPay\Services\Sms\ParserConfig;
use CartBecart\CardPay\Services\Sms\SmsParser;
use CartBecart\CardPay\Services\Sms\SmsParseResult;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| Glyph constants — byte-exact so the §A2/§A3 normalization is actually
| exercised. The Appendix B sample is written with ARABIC yeh/kaf; the parser
| config uses PERSIAN yeh. Matching only works because the pipeline folds them.
|--------------------------------------------------------------------------
*/
$pYeh = "\u{06CC}";      // ی Persian yeh (used in the parser config)
$aYeh = "\u{064A}";      // ي Arabic yeh (used in the incoming SMS)
$aKaf = "\u{0643}";      // ك Arabic kaf (used in the incoming SMS)
$faDigits = "\u{06F0}-\u{06F9}"; // ۰-۹ range for the char class
$arThousand = "\u{066C}"; // ٬ Arabic thousands separator

// Saman preset (Appendix B), Persian-yeh spelling.
$samanRegex = "/وار{$pYeh}ز\\s+مبلغ\\s+(?<amount>[0-9{$faDigits},{$arThousand} ]+)\\s*ر{$pYeh}ال/u";
$samanConfig = new ParserConfig(
    amountRegex: $samanRegex,
    positiveKeywords: ["وار{$pYeh}ز"],
    negativeKeywords: ['برداشت'],
);

// Appendix B sample SMS, Arabic-yeh/kaf spelling.
$samanSample = "بان{$aKaf} سامان\nوار{$aYeh}ز مبلغ  1,000,000ر{$aYeh}ال\n".
    "به 9001-800-2156834-1\nمانده 1,397,604\n1405/5/13\n1:57";

$parser = new SmsParser;
$at = Carbon::parse('2026-08-24 10:20:30', 'UTC');

test('Appendix B Saman sample parses to 1000000 credit (glyph-folded)', function () use ($parser, $samanConfig, $samanSample, $at) {
    $result = $parser->parse($samanSample, $samanConfig, $at);

    expect($result->status)->toBe(ParseStatus::Parsed)
        ->and($result->isOk())->toBeTrue()
        ->and($result->amount)->toBe(1_000_000)
        ->and($result->transactionType)->toBe(SmsParseResult::TYPE_CREDIT)
        ->and($result->failureReason)->toBeNull()
        ->and($result->transactionAt?->equalTo($at))->toBeTrue();
});

test('a withdrawal SMS is ignored before anything else runs', function () use ($parser, $samanConfig, $samanSample, $at) {
    // Contains BOTH برداشت (negative) and the full واریز…ریال deposit phrase:
    // the negative keyword must short-circuit first (ordering guarantee).
    $withdrawal = 'برداشت '.$samanSample;

    $result = $parser->parse($withdrawal, $samanConfig, $at);

    expect($result->status)->toBe(ParseStatus::Ignored)
        ->and($result->amount)->toBeNull()
        ->and($result->transactionType)->toBe(SmsParseResult::TYPE_DEBIT)
        ->and($result->failureReason)->toBe(SmsParseResult::REASON_NEGATIVE);
});

test('missing deposit keyword fails as positive_keyword_not_found', function () use ($parser, $pYeh, $at) {
    $config = new ParserConfig(
        amountRegex: "/مبلغ\\s+(?<amount>[0-9]+)\\s*ر{$pYeh}ال/u",
        positiveKeywords: ["وار{$pYeh}ز"],
        negativeKeywords: ['برداشت'],
    );

    // Has an amount phrase but no واریز keyword → must fail before the regex.
    $result = $parser->parse("شارژ مبلغ 5000 ر{$pYeh}ال", $config, $at);

    expect($result->status)->toBe(ParseStatus::Failed)
        ->and($result->failureReason)->toBe(SmsParseResult::REASON_NO_POSITIVE)
        ->and($result->transactionType)->toBe(SmsParseResult::TYPE_UNKNOWN);
});

test('a deposit keyword present but no amount match fails as amount_not_found', function () use ($parser, $samanConfig, $pYeh, $at) {
    $result = $parser->parse("وار{$pYeh}ز انجام شد", $samanConfig, $at);

    expect($result->status)->toBe(ParseStatus::Failed)
        ->and($result->failureReason)->toBe(SmsParseResult::REASON_NO_AMOUNT);
});

test('a non-numeric captured amount fails as invalid_amount', function () use ($parser, $pYeh, $at) {
    $config = new ParserConfig(
        amountRegex: "/مبلغ (?<amount>.+) ر{$pYeh}ال/u",
        positiveKeywords: [],
        negativeKeywords: [],
    );

    $result = $parser->parse("مبلغ ABC ر{$pYeh}ال", $config, $at);

    expect($result->status)->toBe(ParseStatus::Failed)
        ->and($result->failureReason)->toBe(SmsParseResult::REASON_INVALID_AMOUNT);
});

test('an invalid regex pattern is a parse failure, never a fatal (§A3 defensive)', function () use ($parser, $at) {
    $config = new ParserConfig(
        amountRegex: '/(unclosed group', // deliberately broken
        positiveKeywords: [],
        negativeKeywords: [],
    );

    $result = $parser->parse('مبلغ 1000 ریال', $config, $at);

    expect($result->status)->toBe(ParseStatus::Failed)
        ->and($result->failureReason)->toBe(SmsParseResult::REASON_NO_AMOUNT);
});

test('Persian and Arabic digits with separators parse correctly', function () use ($parser, $at) {
    $config = new ParserConfig(
        amountRegex: "/مبلغ\\s+(?<amount>[0-9\u{06F0}-\u{06F9},\u{066C} ]+)/u",
        positiveKeywords: [],
        negativeKeywords: [],
    );

    // ۱۲۳٬۴۵۶ (Persian digits + Arabic thousands sep) → 123456
    $result = $parser->parse('مبلغ ۱۲۳٬۴۵۶ تومان', $config, $at);

    expect($result->status)->toBe(ParseStatus::Parsed)
        ->and($result->amount)->toBe(123_456);
});

test('capture falls back to group 1 then whole match', function () use ($parser, $at) {
    // Group 1 (no named group).
    $g1 = new ParserConfig('/مبلغ\s+([0-9]+)/u');
    expect($parser->parse('مبلغ 700 تومان', $g1, $at)->amount)->toBe(700);

    // Whole-match fallback (no groups at all).
    $g0 = new ParserConfig('/[0-9]+/');
    expect($parser->parse('deposit 850 done', $g0, $at)->amount)->toBe(850);
});

test('blank keywords are ignored and never match everything', function () use ($parser, $at) {
    // A config whose only positive keyword is blank behaves as "no positive
    // constraint" — it must not fail as not-found, nor match on the empty string.
    $config = new ParserConfig(
        amountRegex: '/مبلغ\s+(?<amount>[0-9]+)/u',
        positiveKeywords: ['', '   '],
        negativeKeywords: [''],
    );

    $result = $parser->parse('مبلغ 700 تومان', $config, $at);

    expect($result->status)->toBe(ParseStatus::Parsed)
        ->and($result->amount)->toBe(700);
});

test('an Arabic-yeh deposit keyword in the SMS matches a Persian-yeh config keyword', function () use ($parser, $pYeh, $aYeh, $at) {
    $config = new ParserConfig(
        amountRegex: '/مبلغ\s+(?<amount>[0-9]+)/u',
        positiveKeywords: ["وار{$pYeh}ز"], // Persian yeh in config
        negativeKeywords: [],
    );

    // Arabic yeh in the incoming text — folds to match.
    $result = $parser->parse("وار{$aYeh}ز مبلغ 900 تومان", $config, $at);

    expect($result->status)->toBe(ParseStatus::Parsed)
        ->and($result->amount)->toBe(900);
});
