<?php

declare(strict_types=1);

use CartBecart\CardPay\Services\Security\Crypto;

/** A deterministic, valid `base64:` application key for tests. */
function testKey(string $seed = "\x01"): string
{
    return 'base64:'.base64_encode(str_repeat($seed, 32));
}

describe('encrypt / decrypt round trip', function () {
    it('recovers the original plaintext', function (string $plaintext) {
        $crypto = new Crypto(testKey());

        expect($crypto->decrypt($crypto->encrypt($plaintext)))->toBe($plaintext);
    })->with([
        'ascii' => ['6037-9911-1234-5678'],
        'persian' => ['علی رضایی'],
        'empty' => [''],
        'long' => [str_repeat('A', 4096)],
        'binary-ish' => ["\x00\x01\x02\xffgcm\x00"],
    ]);

    it('is deterministic across instances sharing the same key', function () {
        $envelope = (new Crypto(testKey()))->encrypt('IR820540102680020817909002');

        expect((new Crypto(testKey()))->decrypt($envelope))->toBe('IR820540102680020817909002');
    });
});

describe('envelope format (§SR-1)', function () {
    it('is base64 of IV[12] ‖ TAG[16] ‖ CIPHERTEXT', function () {
        $plaintext = 'hello';
        $raw = base64_decode((new Crypto(testKey()))->encrypt($plaintext), true);

        // GCM ciphertext length equals plaintext length, so total = 12 + 16 + len.
        expect($raw)->not->toBeFalse()
            ->and(strlen($raw))->toBe(12 + 16 + strlen($plaintext));
    });

    it('produces a fresh IV per call, so identical plaintext yields distinct ciphertext', function () {
        $crypto = new Crypto(testKey());

        expect($crypto->encrypt('same'))->not->toBe($crypto->encrypt('same'));
    });
});

describe('authentication & tamper resistance', function () {
    it('fails to decrypt under the wrong key', function () {
        $envelope = (new Crypto(testKey("\x01")))->encrypt('secret');

        expect(fn () => (new Crypto(testKey("\x02")))->decrypt($envelope))
            ->toThrow(RuntimeException::class);
    });

    it('rejects a tampered ciphertext (GCM tag mismatch)', function () {
        $crypto = new Crypto(testKey());
        $raw = base64_decode($crypto->encrypt('secret'), true);

        // Flip the last byte of the ciphertext body.
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\xff";

        expect(fn () => $crypto->decrypt(base64_encode($raw)))
            ->toThrow(RuntimeException::class);
    });

    it('rejects malformed or truncated envelopes', function (string $payload) {
        expect(fn () => (new Crypto(testKey()))->decrypt($payload))
            ->toThrow(RuntimeException::class);
    })->with([
        'not base64' => ['@@@@not-base64@@@@'],
        'too short' => [base64_encode('tiny')],
        'empty' => [''],
    ]);
});

describe('key derivation', function () {
    it('accepts a raw (non-base64) key string', function () {
        $crypto = new Crypto('a-plain-non-prefixed-key');

        expect($crypto->decrypt($crypto->encrypt('x')))->toBe('x');
    });

    it('throws when the application key is empty', function () {
        expect(fn () => new Crypto(''))->toThrow(RuntimeException::class);
    });
});

describe('fingerprint', function () {
    it('is a deterministic 64-char hex sha256', function () {
        $fp = Crypto::fingerprint('device-secret');

        expect($fp)->toBe(hash('sha256', 'device-secret'))
            ->and($fp)->toHaveLength(64)
            ->and($fp)->toMatch('/^[0-9a-f]{64}$/');
    });

    it('differs for different secrets', function () {
        expect(Crypto::fingerprint('a'))->not->toBe(Crypto::fingerprint('b'));
    });
});
