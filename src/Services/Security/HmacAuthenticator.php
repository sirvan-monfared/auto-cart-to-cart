<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Security;

use CartBecart\CardPay\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * HMAC request authentication for the merchant API and trusted devices (§A5).
 *
 * The signed canonical string is, joined by LF (`\n`):
 *
 *     METHOD
 *     PATH
 *     RAW_QUERY_STRING
 *     sha256_hex(RAW_BODY)
 *     UNIX_TS_SECONDS
 *     NONCE
 *
 * and `signature = lowercase_hex(HMAC_SHA256(canonical, SECRET))`. Verification
 * runs the §A5 checks in a fixed order and, crucially, performs NO business
 * logic on failure (AC-3): any header/key problem throws the scheme's key error,
 * any timestamp/nonce/signature/replay problem throws its signature error —
 * both surfaced as 401 by the handler.
 *
 * {@see canonical()} and {@see sign()} are pure and covered by fixed-vector
 * tests so the wire format can never silently drift. Comparisons use
 * {@see hash_equals()} (constant time); the single-use nonce guard is delegated
 * to the caller so each surface writes to its own nonce table.
 */
final class HmacAuthenticator
{
    public function __construct(
        private readonly int $tolerance,
        private readonly int $nonceMin,
        private readonly int $nonceMax,
    ) {}

    /**
     * Build the canonical string (§A5). Pure — no request or clock access.
     */
    public function canonical(
        string $method,
        string $path,
        string $rawQuery,
        #[SensitiveParameter] string $rawBody,
        string $timestamp,
        string $nonce,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $rawQuery,
            hash('sha256', $rawBody),
            $timestamp,
            $nonce,
        ]);
    }

    /**
     * Lowercase-hex HMAC-SHA256 of the canonical string under the secret.
     */
    public function sign(string $canonical, #[SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Verify a request end-to-end (§A5). Returns the validated presented key.
     *
     * @param  Closure(string): (?string)  $resolveSecret  Presented key → plaintext
     *                                                     secret of the active subject, or null when the key is unknown/inactive.
     * @param  Closure(string, \DateTimeInterface): bool  $storeNonce  Persist the nonce
     *                                                                 with the given expiry; returns false when it already existed (replay).
     *
     * @throws ApiException keyError (missing/unknown key) or signatureError
     *                      (stale timestamp, bad nonce, bad signature, replay).
     */
    public function authenticate(
        Request $request,
        HmacScheme $scheme,
        Closure $resolveSecret,
        Closure $storeNonce,
    ): string {
        $key = trim((string) $request->header($scheme->keyHeader, ''));
        $timestamp = trim((string) $request->header($scheme->timestampHeader, ''));
        $nonce = trim((string) $request->header($scheme->nonceHeader, ''));
        $signature = trim((string) $request->header($scheme->signatureHeader, ''));

        // 1. Every header must be present.
        if ($key === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            throw new ApiException($scheme->keyError);
        }

        // 2. Key must resolve to an active subject with a usable secret.
        $secret = $resolveSecret($key);
        if ($secret === null || $secret === '') {
            throw new ApiException($scheme->keyError);
        }

        // 3. Timestamp must be a plain integer within the tolerance window.
        if (! $this->timestampFresh($timestamp)) {
            throw new ApiException($scheme->signatureError);
        }

        // 4. Nonce length must be within bounds.
        $nonceLength = strlen($nonce);
        if ($nonceLength < $this->nonceMin || $nonceLength > $this->nonceMax) {
            throw new ApiException($scheme->signatureError);
        }

        // 5. Signature must match, compared in constant time.
        $expected = $this->sign(
            $this->canonical(
                $request->getMethod(),
                $request->getPathInfo(),
                (string) $request->server->get('QUERY_STRING', ''),
                $request->getContent(),
                $timestamp,
                $nonce,
            ),
            $secret,
        );

        if (! hash_equals($expected, $signature)) {
            throw new ApiException($scheme->signatureError);
        }

        // 6. Nonce must be single-use within the tolerance window.
        if (! $storeNonce($nonce, now()->addSeconds($this->tolerance))) {
            throw new ApiException($scheme->signatureError);
        }

        return $key;
    }

    /**
     * Whether $timestamp is a bare integer within ±tolerance of now. Anything
     * non-numeric fails closed.
     */
    public function timestampFresh(string $timestamp): bool
    {
        if (preg_match('/^\d{1,20}$/', $timestamp) !== 1) {
            return false;
        }

        return abs(now()->getTimestamp() - (int) $timestamp) <= $this->tolerance;
    }
}
