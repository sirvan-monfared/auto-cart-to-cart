<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Middleware;

use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Services\RateLimiting\DbRateLimiter;
use CartBecart\CardPay\Services\Security\Crypto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shortcut-mode gate for `shortcut-sms` (§FR-9 / §A5 shortcut mode).
 *
 * iOS Shortcuts cannot compute HMAC, so they present the static pair
 * `X-Device-Key` + `X-Device-Secret`. The secret is validated by comparing its
 * SHA-256 fingerprint against the stored one with {@see hash_equals()} —
 * constant time, and the plaintext secret never needs to be decrypted. When the
 * headers are absent, JSON body fields `device_key`/`device_secret` are accepted
 * instead (headers win) because Shortcuts sometimes cannot set headers.
 *
 * Same discipline as the HMAC gate: per-IP credential limit first, then auth,
 * then the per-device quota; NO business logic on failure. No nonce table here —
 * dedupe of relayed SMS is enforced downstream on UNIQUE(device_id, message_id),
 * which is the replay surface that actually matters for this endpoint.
 */
final class DeviceShortcutAuth
{
    public const DEVICE_ATTRIBUTE = DeviceHmacAuth::DEVICE_ATTRIBUTE;

    public function __construct(private readonly DbRateLimiter $rateLimiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->rateLimiter->hit(
            'device',
            'ip:'.(string) $request->ip(),
            (int) config('cardpay.rate_limits.device', 60),
        );

        $key = trim((string) ($request->headers->get('X-Device-Key') ?? $request->input('device_key', '')));
        $secret = trim((string) ($request->headers->get('X-Device-Secret') ?? $request->input('device_secret', '')));

        if ($key === '' || $secret === '') {
            throw ApiException::invalidDeviceKey();
        }

        $device = Device::query()->where('device_key', $key)->first();

        if (! $device instanceof Device || ! $device->isUsable()) {
            throw ApiException::invalidDeviceKey();
        }

        if (! hash_equals($device->secret_fingerprint, Crypto::fingerprint($secret))) {
            throw ApiException::invalidDeviceSignature();
        }

        $request->attributes->set(self::DEVICE_ATTRIBUTE, $device);

        $this->rateLimiter->hit(
            'device',
            'device:'.$device->id,
            (int) config('cardpay.rate_limits.device', 60),
        );

        return $next($request);
    }
}
