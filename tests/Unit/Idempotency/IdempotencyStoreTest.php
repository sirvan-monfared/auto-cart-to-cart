<?php

declare(strict_types=1);

use CartBecart\CardPay\Services\Idempotency\IdempotencyStore;

/*
|--------------------------------------------------------------------------
| §A8 request_hash — stable JSON hashing
|--------------------------------------------------------------------------
|
| Replay-vs-conflict correctness rests entirely on this hash: it MUST be
| insensitive to object key order (so a re-serialized identical body replays)
| yet sensitive to values and to list order (so a genuinely different body
| conflicts). These are pure, framework-free assertions.
|
*/

function idemStore(): IdempotencyStore
{
    return new IdempotencyStore;
}

describe('hashRequest()', function () {
    it('is a 64-char lowercase hex sha256', function () {
        expect(idemStore()->hashRequest(['amount' => 10000]))->toMatch('/^[0-9a-f]{64}$/');
    });

    it('is stable across calls for the same body', function () {
        $body = ['amount' => 10000, 'customer' => ['name' => 'Ali', 'mobile' => '0912']];

        expect(idemStore()->hashRequest($body))->toBe(idemStore()->hashRequest($body));
    });

    it('ignores top-level key ORDER', function () {
        expect(idemStore()->hashRequest(['a' => 1, 'b' => 2]))
            ->toBe(idemStore()->hashRequest(['b' => 2, 'a' => 1]));
    });

    it('ignores nested object key order', function () {
        expect(idemStore()->hashRequest(['meta' => ['x' => 1, 'y' => 2], 'amount' => 5]))
            ->toBe(idemStore()->hashRequest(['amount' => 5, 'meta' => ['y' => 2, 'x' => 1]]));
    });

    it('is SENSITIVE to list order (arrays are ordered)', function () {
        expect(idemStore()->hashRequest(['items' => [1, 2, 3]]))
            ->not->toBe(idemStore()->hashRequest(['items' => [3, 2, 1]]));
    });

    it('is sensitive to values', function () {
        expect(idemStore()->hashRequest(['amount' => 10000]))
            ->not->toBe(idemStore()->hashRequest(['amount' => 10001]));
    });

    it('distinguishes a missing key from a null value', function () {
        expect(idemStore()->hashRequest(['amount' => 1]))
            ->not->toBe(idemStore()->hashRequest(['amount' => 1, 'note' => null]));
    });

    it('distinguishes types (string "1" vs int 1)', function () {
        expect(idemStore()->hashRequest(['amount' => 1]))
            ->not->toBe(idemStore()->hashRequest(['amount' => '1']));
    });

    it('normalizes key order inside objects nested within lists', function () {
        expect(idemStore()->hashRequest(['lines' => [['sku' => 'A', 'qty' => 2]]]))
            ->toBe(idemStore()->hashRequest(['lines' => [['qty' => 2, 'sku' => 'A']]]));
    });

    it('preserves unicode content deterministically', function () {
        $a = idemStore()->hashRequest(['name' => 'علی رضایی', 'desc' => 'پرداخت']);
        $b = idemStore()->hashRequest(['desc' => 'پرداخت', 'name' => 'علی رضایی']);

        expect($a)->toBe($b);
    });
});
