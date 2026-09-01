<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Middleware;

use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\DeviceNonce;
use CartBecart\CardPay\Services\RateLimiting\DbRateLimiter;
use CartBecart\CardPay\Services\Security\HmacAuthenticator;
use CartBecart\CardPay\Services\Security\HmacScheme;
use Closure;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trusted-device HMAC gate for `incoming-sms` (§FR-9 / §A5 / §A7).
 *
 * Order matters and is deliberate: a PER-SOURCE-IP credential limit runs FIRST
 * (a brute-forcer must be stoppable before touching any device row), then full
 * HMAC verification, then the per-device quota — never business logic before
 * auth succeeds. On success the {@see Device} is attached to the request; the
 * recognition pipeline itself stamps device stats per accepted message.
 *
 * Closures share a typed holder object rather than `use (&…)` variables: the
 * resolver populates it during authenticate() and the nonce store — invoked
 * later, at §A5 step 6 — reads the same instance (see .ai/rules/middleware.md).
 */
final class DeviceHmacAuth
{
    /** Request attribute under which the authenticated device is exposed. */
    public const DEVICE_ATTRIBUTE = 'cardpay_device';

    public function __construct(
        private readonly HmacAuthenticator $authenticator,
        private readonly DbRateLimiter $rateLimiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // §FR-9: per-source-IP limit on credential attempts — the pre-auth
        // brute-force guard. Keyed by IP, so callers cannot exhaust one another.
        $this->rateLimiter->hit(
            'device',
            'ip:'.(string) $request->ip(),
            (int) config('cardpay.rate_limits.device', 60),
        );

        $resolved = new class
        {
            public ?Device $device = null;
        };

        $this->authenticator->authenticate(
            $request,
            HmacScheme::device(),
            function (string $deviceKey) use ($resolved): ?string {
                $candidate = Device::query()->where('device_key', $deviceKey)->first();

                if ($candidate === null || ! $candidate->isUsable()) {
                    return null;
                }

                $resolved->device = $candidate;

                // Decrypted transparently by the Encrypted cast (§SR-1).
                return $candidate->device_secret_encrypted;
            },
            fn (string $nonce, DateTimeInterface $expiresAt): bool => $this->storeNonce($resolved->device, $nonce, $expiresAt),
        );

        // authenticate() returns only after the resolver succeeded, so the device
        // is set here; guard anyway and fail closed.
        if (! $resolved->device instanceof Device) {
            throw ApiException::invalidDeviceKey();
        }

        $request->attributes->set(self::DEVICE_ATTRIBUTE, $resolved->device);

        // §A7: per-device fixed window — applied ONLY after successful auth.
        $this->rateLimiter->hit(
            'device',
            'device:'.$resolved->device->id,
            (int) config('cardpay.rate_limits.device', 60),
        );

        return $next($request);
    }

    /**
     * Persist a single-use per-device nonce; false when it already existed
     * within its window — a replay caught by UNIQUE(device_id, nonce) (§SR-4).
     */
    private function storeNonce(?Device $device, string $nonce, DateTimeInterface $expiresAt): bool
    {
        if (! $device instanceof Device) {
            return false;
        }

        try {
            DeviceNonce::query()->create([
                'device_id' => $device->id,
                'nonce' => $nonce,
                'expires_at' => $expiresAt,
            ]);

            return true;
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return false;
            }

            throw $e;
        }
    }
}
