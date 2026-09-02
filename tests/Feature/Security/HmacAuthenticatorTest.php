<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\ApiErrorCode;
use CartBecart\CardPay\Services\Security\HmacAuthenticator;
use CartBecart\CardPay\Services\Security\HmacScheme;
use CartBecart\CardPay\Tests\Support\HmacRequestSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| §A5 authenticate() — ordered checks, replay, and no-side-effects-on-failure
|--------------------------------------------------------------------------
|
| The authenticator is resolved from the container (app(HmacAuthenticator)) so
| these also prove the CardPayServiceProvider binding reads config correctly.
| Requests are signed by the INDEPENDENT CartBecart\CardPay\Tests\Support\HmacRequestSigner.
|
| AC-3: on ANY failure the authenticator must perform no business logic — the
| $spy object below records every resolveSecret/storeNonce call so we can assert
| the nonce is never stored (and the key never resolved) once a check fails. The
| spy is an object so arrow-function closures (which capture by value) still see
| its mutations.
|
*/

const M_KEY = 'pk_live_merchant_key';
const M_SECRET = 'merchant-shared-secret-xyz';
const M_NONCE = 'nonce-1234567890'; // 16 chars, within [12, 190]

beforeEach(function () {
    // Freeze the clock so timestamp-window assertions are exact.
    Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A fresh call-recording spy. */
function authSpy(): object
{
    return (object) ['resolved' => [], 'stored' => []];
}

/**
 * Build a merchant request and sign it (by default) for M_SECRET, deriving the
 * canonical components from the request itself. Overrides let a test corrupt a
 * single input while keeping the signature otherwise valid.
 *
 * @param  array<string, mixed>  $o
 */
function signedMerchantRequest(array $o = []): Request
{
    $uri = $o['uri'] ?? '/api/v1/payments?foo=bar&baz=1';
    $method = $o['method'] ?? 'POST';
    $body = $o['body'] ?? '{"amount":10000,"order":"A-1"}';
    $key = $o['key'] ?? M_KEY;
    $secret = $o['secret'] ?? M_SECRET;
    $nonce = $o['nonce'] ?? M_NONCE;
    $timestamp = (string) ($o['timestamp'] ?? now()->getTimestamp());

    $request = Request::create($uri, $method, [], [], [], [], $body);

    (new HmacRequestSigner)->sign($request, $key, $secret, $nonce, $timestamp);

    return $request;
}

/**
 * Run the merchant authenticator with instrumented closures. The $spy object's
 * `resolved`/`stored` arrays are appended to as each closure fires, so they are
 * observable even after authenticate() throws.
 *
 * @param  array<string, string>  $secretFor  key => plaintext secret
 * @param  list<string>  $seenNonces  nonces already used (to force a replay)
 */
function authenticateMerchant(Request $request, object $spy, array $secretFor = [M_KEY => M_SECRET], array $seenNonces = []): string
{
    $seen = $seenNonces;

    $resolve = function (string $k) use ($spy, $secretFor): ?string {
        $spy->resolved[] = $k;

        return $secretFor[$k] ?? null;
    };

    $store = function (string $n, DateTimeInterface $expiresAt) use ($spy, &$seen): bool {
        $spy->stored[] = $n;
        if (in_array($n, $seen, true)) {
            return false;
        }
        $seen[] = $n;

        return true;
    };

    return app(HmacAuthenticator::class)->authenticate($request, HmacScheme::merchant(), $resolve, $store);
}

describe('happy path', function () {
    it('authenticates a correctly signed request and stores the nonce exactly once', function () {
        $spy = authSpy();

        $result = authenticateMerchant(signedMerchantRequest(), $spy);

        expect($result)->toBe(M_KEY)
            ->and($spy->resolved)->toBe([M_KEY])
            ->and($spy->stored)->toBe([M_NONCE]);
    });
});

describe('header presence (§A5 step 1)', function () {
    it('rejects a request missing a required header with the KEY error and touches nothing', function (string $header) {
        $request = signedMerchantRequest();
        $request->headers->remove($header);
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($request, $spy), ApiErrorCode::InvalidApiKey);

        // No business logic ran: the key was never resolved, the nonce never stored.
        expect($spy->resolved)->toBe([])
            ->and($spy->stored)->toBe([]);
    })->with([
        'key' => ['X-CardPay-Key'],
        'timestamp' => ['X-CardPay-Timestamp'],
        'nonce' => ['X-CardPay-Nonce'],
        'signature' => ['X-CardPay-Signature'],
    ]);
});

describe('key resolution (§A5 step 2)', function () {
    it('rejects an unknown key before ever checking the signature', function () {
        $request = signedMerchantRequest(['key' => 'pk_unknown']);
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($request, $spy), ApiErrorCode::InvalidApiKey);

        expect($spy->resolved)->toBe(['pk_unknown'])
            ->and($spy->stored)->toBe([]);
    });

    it('rejects a key resolving to an empty secret (inactive subject)', function () {
        $request = signedMerchantRequest(['key' => 'pk_blank']);
        $spy = authSpy();

        expectApiError(
            fn () => authenticateMerchant($request, $spy, ['pk_blank' => '']),
            ApiErrorCode::InvalidApiKey,
        );

        expect($spy->stored)->toBe([]);
    });
});

describe('timestamp freshness (§A5 step 3)', function () {
    it('rejects a timestamp older than the tolerance', function () {
        $request = signedMerchantRequest(['timestamp' => (string) (now()->getTimestamp() - 301)]);
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($request, $spy), ApiErrorCode::InvalidSignature);
        expect($spy->stored)->toBe([]);
    });

    it('rejects a timestamp further in the future than the tolerance', function () {
        $request = signedMerchantRequest(['timestamp' => (string) (now()->getTimestamp() + 301)]);
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($request, $spy), ApiErrorCode::InvalidSignature);
        expect($spy->stored)->toBe([]);
    });

    it('accepts a timestamp exactly at the tolerance boundary', function (int $delta) {
        $request = signedMerchantRequest(['timestamp' => (string) (now()->getTimestamp() + $delta)]);
        $spy = authSpy();

        expect(authenticateMerchant($request, $spy))->toBe(M_KEY);
    })->with([
        'past edge' => [-300],
        'future edge' => [300],
    ]);
});

describe('nonce length (§A5 step 4)', function () {
    it('rejects a nonce outside the configured length bounds', function (int $length) {
        $request = signedMerchantRequest(['nonce' => str_repeat('a', $length)]);
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($request, $spy), ApiErrorCode::InvalidSignature);
        expect($spy->stored)->toBe([]);
    })->with([
        'too short' => [11],
        'too long' => [191],
    ]);

    it('accepts a nonce at the length bounds', function (int $length) {
        $request = signedMerchantRequest(['nonce' => str_repeat('n', $length)]);
        $spy = authSpy();

        expect(authenticateMerchant($request, $spy))->toBe(M_KEY);
    })->with([
        'min' => [12],
        'max' => [190],
    ]);
});

describe('signature match (§A5 step 5)', function () {
    it('rejects a forged signature and never stores the nonce', function () {
        $request = signedMerchantRequest();
        $request->headers->set('X-CardPay-Signature', str_repeat('0', 64));
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($request, $spy), ApiErrorCode::InvalidSignature);

        // Key was resolved (step 2 passed) but the nonce must NOT be consumed.
        expect($spy->resolved)->toBe([M_KEY])
            ->and($spy->stored)->toBe([]);
    });

    it('rejects a body altered after signing (the body is covered)', function () {
        $signed = signedMerchantRequest();
        $tampered = Request::create('/api/v1/payments?foo=bar&baz=1', 'POST', [], [], [], [], '{"amount":99999,"order":"A-1"}');
        $tampered->headers->replace($signed->headers->all());
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($tampered, $spy), ApiErrorCode::InvalidSignature);
    });

    it('rejects an altered query string (the query is covered)', function () {
        $signed = signedMerchantRequest();
        $tampered = Request::create('/api/v1/payments?foo=bar&baz=2', 'POST', [], [], [], [], '{"amount":10000,"order":"A-1"}');
        $tampered->headers->replace($signed->headers->all());
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($tampered, $spy), ApiErrorCode::InvalidSignature);
    });

    it('rejects an altered HTTP method (the method is covered)', function () {
        $signed = signedMerchantRequest();
        $tampered = Request::create('/api/v1/payments?foo=bar&baz=1', 'PUT', [], [], [], [], '{"amount":10000,"order":"A-1"}');
        $tampered->headers->replace($signed->headers->all());
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($tampered, $spy), ApiErrorCode::InvalidSignature);
    });
});

describe('replay (§A5 step 6)', function () {
    it('rejects a nonce that was already used, having attempted the store once', function () {
        $request = signedMerchantRequest();
        $spy = authSpy();

        expectApiError(
            fn () => authenticateMerchant($request, $spy, [M_KEY => M_SECRET], [M_NONCE]),
            ApiErrorCode::InvalidSignature,
        );

        // The store was attempted exactly once and rejected the reused nonce.
        expect($spy->stored)->toBe([M_NONCE]);
    });
});

describe('check ordering', function () {
    it('reports the key error first when both the key and signature are invalid', function () {
        $request = signedMerchantRequest(['key' => 'pk_unknown']);
        $request->headers->set('X-CardPay-Signature', str_repeat('0', 64));
        $spy = authSpy();

        expectApiError(fn () => authenticateMerchant($request, $spy), ApiErrorCode::InvalidApiKey);
        expect($spy->stored)->toBe([]);
    });

    it('reports the timestamp (signature) error before the nonce is consumed', function () {
        // Stale timestamp AND a nonce that is already used — timestamp is checked
        // first, so the store is never attempted.
        $request = signedMerchantRequest(['timestamp' => (string) (now()->getTimestamp() - 400)]);
        $spy = authSpy();

        expectApiError(
            fn () => authenticateMerchant($request, $spy, [M_KEY => M_SECRET], [M_NONCE]),
            ApiErrorCode::InvalidSignature,
        );
        expect($spy->stored)->toBe([]);
    });
});

describe('device surface', function () {
    it('authenticates a valid device request and fails with device-specific codes', function () {
        $signer = HmacRequestSigner::device();

        $valid = Request::create('/api/device/v1/reports', 'POST', [], [], [], [], '{"sms":"..."}');
        $signer->sign($valid, 'dev_key', 'dev_secret', M_NONCE, (string) now()->getTimestamp());

        $result = app(HmacAuthenticator::class)->authenticate(
            $valid,
            HmacScheme::device(),
            fn (string $k): ?string => $k === 'dev_key' ? 'dev_secret' : null,
            fn (string $n, DateTimeInterface $e): bool => true,
        );
        expect($result)->toBe('dev_key');

        // Missing header → device KEY error.
        $missing = Request::create('/api/device/v1/reports', 'POST', [], [], [], [], '{"sms":"..."}');
        $signer->sign($missing, 'dev_key', 'dev_secret', M_NONCE, (string) now()->getTimestamp());
        $missing->headers->remove('X-Device-Signature');
        expectApiError(
            fn () => app(HmacAuthenticator::class)->authenticate(
                $missing,
                HmacScheme::device(),
                fn (string $k): ?string => 'dev_secret',
                fn (string $n, DateTimeInterface $e): bool => true,
            ),
            ApiErrorCode::InvalidDeviceKey,
        );

        // Forged signature → device SIGNATURE error.
        $forged = Request::create('/api/device/v1/reports', 'POST', [], [], [], [], '{"sms":"..."}');
        $signer->sign($forged, 'dev_key', 'dev_secret', M_NONCE, (string) now()->getTimestamp());
        $forged->headers->set('X-Device-Signature', str_repeat('0', 64));
        expectApiError(
            fn () => app(HmacAuthenticator::class)->authenticate(
                $forged,
                HmacScheme::device(),
                fn (string $k): ?string => 'dev_secret',
                fn (string $n, DateTimeInterface $e): bool => true,
            ),
            ApiErrorCode::InvalidDeviceSignature,
        );
    });
});
