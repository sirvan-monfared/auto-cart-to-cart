<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Tests\Support;

use Illuminate\Http\Request;

/**
 * Signs requests exactly as a correct merchant/device SDK would (§A5).
 *
 * This is a DELIBERATELY INDEPENDENT reimplementation of the canonical string:
 * it does not import or call the production `HmacAuthenticator`, so if that
 * canonical format ever drifts (field order, separator, body hash), a
 * valid-signature test signed here will stop matching — the regression surfaces
 * instead of being masked by both sides sharing one buggy builder.
 *
 * The method/path/query/body are read from the built request via Symfony's own
 * accessors (the same contract the authenticator relies on), so a signature made
 * here is genuinely valid for that exact request regardless of how Symfony
 * normalizes the query string.
 */
final class HmacRequestSigner
{
    public function __construct(
        private readonly string $keyHeader = 'X-CardPay-Key',
        private readonly string $timestampHeader = 'X-CardPay-Timestamp',
        private readonly string $nonceHeader = 'X-CardPay-Nonce',
        private readonly string $signatureHeader = 'X-CardPay-Signature',
    ) {}

    /** Header names for the trusted-device surface. */
    public static function device(): self
    {
        return new self('X-Device-Key', 'X-Device-Timestamp', 'X-Device-Nonce', 'X-Device-Signature');
    }

    /**
     * Independent §A5 canonical string for raw request components.
     */
    public function canonical(
        string $method,
        string $path,
        string $rawQuery,
        string $rawBody,
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
     * The signed header set for the given request components.
     *
     * @return array<string, string>
     */
    public function headers(
        string $method,
        string $path,
        string $rawQuery,
        string $rawBody,
        string $key,
        string $secret,
        string $nonce,
        string $timestamp,
    ): array {
        $signature = hash_hmac(
            'sha256',
            $this->canonical($method, $path, $rawQuery, $rawBody, $timestamp, $nonce),
            $secret,
        );

        return [
            $this->keyHeader => $key,
            $this->timestampHeader => $timestamp,
            $this->nonceHeader => $nonce,
            $this->signatureHeader => $signature,
        ];
    }

    /**
     * Sign an existing request in place, deriving the components from the request
     * itself so the signature is valid for exactly what will be verified.
     *
     * @return Request the same instance, with the four auth headers set
     */
    public function sign(Request $request, string $key, string $secret, string $nonce, string $timestamp): Request
    {
        $headers = $this->headers(
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $request->server->get('QUERY_STRING', ''),
            $request->getContent(),
            $key,
            $secret,
            $nonce,
            $timestamp,
        );

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
