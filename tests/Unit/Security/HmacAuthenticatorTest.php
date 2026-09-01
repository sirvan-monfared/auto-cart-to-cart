<?php

declare(strict_types=1);

use CartBecart\CardPay\Services\Security\HmacAuthenticator;

/*
|--------------------------------------------------------------------------
| §A5 canonical string & signature — FIXED VECTORS
|--------------------------------------------------------------------------
|
| These lock the exact wire format so it can never silently drift. The expected
| strings below are hardcoded (computed once, out of band) — NOT re-derived with
| the same functions under test — so a reordering, separator change, or body-hash
| change is caught here rather than passing because both sides moved together.
|
| Vector inputs:
|   method    = POST
|   path      = /api/v1/payments
|   rawQuery  = foo=bar&baz=1
|   rawBody   = {"amount":10000,"order":"A-1"}   (sha256 f8389f27…)
|   timestamp = 1700000000
|   nonce     = nonce-abc-123
|   secret    = s3cr3t_test_key_value
|
*/

/** SUT with production nonce bounds; canonical()/sign() ignore them. */
function hmacSut(): HmacAuthenticator
{
    return new HmacAuthenticator(tolerance: 300, nonceMin: 12, nonceMax: 190);
}

const VEC_BODY = '{"amount":10000,"order":"A-1"}';
const VEC_BODY_SHA256 = 'f8389f2711629283155077a33bfe7ddb6b30e083df3eb985c88e7eb2ff036862';
const VEC_EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
const VEC_CANONICAL = "POST\n/api/v1/payments\nfoo=bar&baz=1\nf8389f2711629283155077a33bfe7ddb6b30e083df3eb985c88e7eb2ff036862\n1700000000\nnonce-abc-123";
const VEC_SECRET = 's3cr3t_test_key_value';
const VEC_SIGNATURE = '1a78010002aa23b9147c12ca5118873d8ccac328516e38484f6c27b69f38dacd';

describe('canonical()', function () {
    it('joins the six fields with LF in the exact §A5 order', function () {
        expect(hmacSut()->canonical('POST', '/api/v1/payments', 'foo=bar&baz=1', VEC_BODY, '1700000000', 'nonce-abc-123'))
            ->toBe(VEC_CANONICAL);
    });

    it('uppercases the HTTP method', function () {
        expect(hmacSut()->canonical('post', '/x', '', '', '1', 'n'))->toStartWith("POST\n");
    });

    it('hashes the body (never includes the raw body)', function () {
        $canonical = hmacSut()->canonical('POST', '/x', '', VEC_BODY, '1', 'n');

        expect($canonical)->toContain(VEC_BODY_SHA256)
            ->and($canonical)->not->toContain('amount');
    });

    it('uses the known empty-string sha256 for an empty body', function () {
        expect(hmacSut()->canonical('GET', '/x', '', '', '1700000000', 'nonce-abc-123'))
            ->toBe("GET\n/x\n\n".VEC_EMPTY_SHA256."\n1700000000\nnonce-abc-123");
    });

    it('keeps the raw query string verbatim (no re-encoding)', function () {
        expect(hmacSut()->canonical('GET', '/x', 'b=2&a=1&a=1', '', '1', 'n'))
            ->toContain("\nb=2&a=1&a=1\n");
    });
});

describe('sign()', function () {
    it('matches the fixed HMAC-SHA256 vector', function () {
        expect(hmacSut()->sign(VEC_CANONICAL, VEC_SECRET))->toBe(VEC_SIGNATURE);
    });

    it('produces 64-char lowercase hex', function () {
        expect(hmacSut()->sign(VEC_CANONICAL, VEC_SECRET))->toMatch('/^[0-9a-f]{64}$/');
    });

    it('changes if any canonical byte changes', function () {
        expect(hmacSut()->sign(VEC_CANONICAL.'x', VEC_SECRET))->not->toBe(VEC_SIGNATURE);
    });

    it('changes under a different secret', function () {
        expect(hmacSut()->sign(VEC_CANONICAL, VEC_SECRET.'!'))->not->toBe(VEC_SIGNATURE);
    });

    it('round-trips: signing the canonical of the vector inputs reproduces the vector signature', function () {
        $canonical = hmacSut()->canonical('POST', '/api/v1/payments', 'foo=bar&baz=1', VEC_BODY, '1700000000', 'nonce-abc-123');

        expect(hmacSut()->sign($canonical, VEC_SECRET))->toBe(VEC_SIGNATURE);
    });
});

describe('timestampFresh() — numeric guard (clock-independent cases)', function () {
    it('rejects non-integer timestamps', function (string $ts) {
        expect(hmacSut()->timestampFresh($ts))->toBeFalse();
    })->with([
        'empty' => [''],
        'alpha' => ['abc'],
        'decimal' => ['1700000000.5'],
        'negative' => ['-1700000000'],
        'hex' => ['0x10'],
        'leading space' => [' 1700000000'],
        'plus sign' => ['+1700000000'],
        'too long' => [str_repeat('9', 21)],
    ]);
});
