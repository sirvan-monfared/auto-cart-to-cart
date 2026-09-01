<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Middleware;

use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Models\ApiNonce;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Services\RateLimiting\DbRateLimiter;
use CartBecart\CardPay\Services\Security\HmacAuthenticator;
use CartBecart\CardPay\Services\Security\HmacScheme;
use Closure;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant API gate (§FR-7 / §A5 / §A7).
 *
 * HMAC authentication runs FIRST; only once it fully succeeds is the
 * per-application rate limit applied — never the reverse. An unauthenticated
 * caller therefore performs NO business logic and can neither spend, probe, nor
 * exhaust another application's rate budget (AC-3). On success the authenticated
 * {@see Application} is attached to the request for the controller, and the
 * key's `last_used_at` is stamped best-effort.
 *
 * The credential lookup lives in the secret resolver handed to the authenticator:
 * it authorises the presented key AND captures the live key + application, so an
 * unknown, inactive, or revoked key (or an inactive application) resolves to a
 * null secret and fails closed with `invalid_api_key` before any state changes.
 */
final class MerchantHmacAuth
{
    /** Request attribute under which the authenticated application is exposed. */
    public const APPLICATION_ATTRIBUTE = 'cardpay_application';

    public function __construct(
        private readonly HmacAuthenticator $authenticator,
        private readonly DbRateLimiter $rateLimiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // A shared holder the auth closures populate. An object handle gives them
        // reference semantics WITHOUT `use (&…)`: the resolver fills it at §A5
        // step 2 and the nonce store reads it at step 6 (same instance), while its
        // typed properties keep the post-auth types unambiguous for the reader.
        $resolved = new class
        {
            public ?ApplicationApiKey $apiKey = null;

            public ?Application $application = null;
        };

        // §A5: full HMAC verification. The resolver both authorises the key and
        // captures the credential + application for post-auth use; the nonce store
        // binds each single-use nonce to that authenticated application.
        $this->authenticator->authenticate(
            $request,
            HmacScheme::merchant(),
            function (string $publicKey) use ($resolved): ?string {
                $candidate = ApplicationApiKey::query()
                    ->where('public_key', $publicKey)
                    ->where('is_active', true)
                    ->whereNull('revoked_at')
                    ->first();

                if ($candidate === null) {
                    return null;
                }

                $owner = $candidate->application;
                if (! $owner instanceof Application || ! $owner->is_active) {
                    return null;
                }

                $resolved->apiKey = $candidate;
                $resolved->application = $owner;

                // Decrypted transparently by the Encrypted cast (§SR-1).
                return $candidate->secret_encrypted;
            },
            // Invoked later, at §A5 step 6: the resolver has already populated the
            // holder, so this binds the single-use nonce to that application.
            fn (string $nonce, DateTimeInterface $expiresAt): bool => $this->storeNonce($resolved->application, $nonce, $expiresAt),
        );

        // authenticate() returns only after the resolver ran and succeeded, so
        // both are set here; guard for static analysis and fail closed otherwise.
        if (! $resolved->apiKey instanceof ApplicationApiKey || ! $resolved->application instanceof Application) {
            throw ApiException::invalidApiKey();
        }

        $apiKey = $resolved->apiKey;
        $application = $resolved->application;

        // Best-effort usage stamp; must never fail an authenticated request, and
        // saved quietly so it raises no model events on this hot path.
        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        // Expose the tenant to the controller. Ownership is re-checked in the
        // service (lookups are application-scoped), so this is a convenience, not
        // the security boundary.
        $request->attributes->set(self::APPLICATION_ATTRIBUTE, $application);

        // §A7: per-application fixed window — applied ONLY after successful auth.
        $this->rateLimiter->hit(
            'api',
            'app:'.$application->id,
            (int) config('cardpay.rate_limits.api', 120),
            (int) config('cardpay.rate_limits.window_seconds', 60),
        );

        return $next($request);
    }

    /**
     * Persist a single-use per-application nonce; false when it already existed
     * within its window — a replay caught by UNIQUE(application_id, nonce)
     * (§A5/§SR-4). A missing application (resolver never succeeded) also fails
     * closed.
     */
    private function storeNonce(?Application $application, string $nonce, DateTimeInterface $expiresAt): bool
    {
        if (! $application instanceof Application) {
            return false;
        }

        try {
            ApiNonce::query()->create([
                'application_id' => $application->id,
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
