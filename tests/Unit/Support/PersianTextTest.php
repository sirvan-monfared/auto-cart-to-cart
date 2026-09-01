<?php

declare(strict_types=1);

use CartBecart\CardPay\Support\PersianText;

describe('normalizeDigits', function () {
    it('maps Persian digits to ASCII', function () {
        expect(PersianText::normalizeDigits('۰۱۲۳۴۵۶۷۸۹'))->toBe('0123456789');
    });

    it('maps Arabic-Indic digits to ASCII', function () {
        expect(PersianText::normalizeDigits('٠١٢٣٤٥٦٧٨٩'))->toBe('0123456789');
    });

    it('leaves ASCII digits and surrounding text intact', function () {
        expect(PersianText::normalizeDigits('order 42 total'))->toBe('order 42 total');
    });

    it('handles a mix of Persian and Arabic-Indic digits', function () {
        expect(PersianText::normalizeDigits('۱٢۳٤'))->toBe('1234');
    });
});

describe('normalizeChars', function () {
    it('folds Arabic yeh and alef maksura to Persian yeh', function () {
        expect(PersianText::normalizeChars('يى'))->toBe('یی');
    });

    it('folds Arabic kaf to Persian keheh', function () {
        expect(PersianText::normalizeChars('ك'))->toBe('ک');
    });

    it('folds teh marbuta and heh-with-yeh to heh', function () {
        expect(PersianText::normalizeChars('ةۀ'))->toBe('هه');
    });
});

describe('toInteger', function () {
    it('parses the Appendix B Saman sample amount', function () {
        // "۱٬۰۰۰٬۰۰۰" — Persian digits grouped by the Arabic thousands separator.
        expect(PersianText::toInteger('۱٬۰۰۰٬۰۰۰'))->toBe(1_000_000);
    });

    it('parses grouped values across separator styles', function (string $input, int $expected) {
        expect(PersianText::toInteger($input))->toBe($expected);
    })->with([
        'ascii comma' => ['1,000,000', 1_000_000],
        'ascii space' => ['1 000 000', 1_000_000],
        'arabic comma' => ['1،000', 1_000],
        'arabic thousands' => ['1٬000', 1_000],
        'non-breaking space' => ["1\u{00A0}000", 1_000],
        'narrow no-break space' => ["1\u{202F}000", 1_000],
        'arabic-indic digits' => ['١٬٠٠٠', 1_000],
        'plain persian' => ['۱۲۳', 123],
        'surrounding whitespace' => ['  500  ', 500],
    ]);

    it('rejects non-positive, empty, and non-numeric fragments', function (string $input) {
        expect(PersianText::toInteger($input))->toBeNull();
    })->with([
        'zero' => ['0'],
        'zeros' => ['000'],
        'negative' => ['-5'],
        'empty' => [''],
        'whitespace only' => ['   '],
        'letters' => ['abc'],
        'mixed alnum' => ['12ab'],
    ]);

    it('does NOT treat a period as a grouping separator (IRR has no minor unit)', function () {
        // If "." were stripped, "12.50" would become 1250 — a silent 100x error.
        // Instead it must be rejected outright.
        expect(PersianText::toInteger('12.50'))->toBeNull();
        expect(PersianText::toInteger('1.000.000'))->toBeNull();
    });

    it('guards against overflow from absurdly long digit runs', function () {
        expect(PersianText::toInteger(str_repeat('9', 19)))->toBeNull();
        expect(PersianText::toInteger(str_repeat('9', 18)))->toBe((int) str_repeat('9', 18));
    });
});
