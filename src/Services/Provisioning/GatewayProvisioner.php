<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Provisioning;

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Services\Security\Crypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns THE gateway application (§16 lite).
 *
 * Full runs many merchant applications and manages them in the panel. Lite
 * runs exactly one, identified by config('cardpay.gateway.slug'), so nothing
 * in a single-shop deployment ever has to know that `application_id` exists:
 * the installer creates the row, the CardPay facade resolves it implicitly,
 * and the admin API edits it as a single resource rather than a collection.
 *
 * Provisioning is idempotent by slug. The API secret is minted exactly once,
 * at creation — re-running the installer against a live gateway must never
 * rotate a credential a merchant is already signing requests with.
 */
final class GatewayProvisioner
{
    public function slug(): string
    {
        $slug = trim((string) config('cardpay.gateway.slug', 'store'));

        return $slug !== '' ? $slug : 'store';
    }

    public function find(): ?Application
    {
        return Application::query()->where('slug', $this->slug())->first();
    }

    /**
     * The gateway application, or a failure that names the fix. Called on the
     * payment path, so an unprovisioned install must not surface as a vague
     * "null" further downstream.
     */
    public function resolve(): Application
    {
        $application = $this->find();

        if (! $application instanceof Application) {
            throw new RuntimeException(
                "CardPay gateway application [{$this->slug()}] does not exist. Run `php artisan cardpay:install`."
            );
        }

        return $application;
    }

    /**
     * Create the gateway application and its first API key if absent.
     * Credentials come back only on the call that actually created it.
     */
    public function provision(): ProvisionResult
    {
        $existing = $this->find();

        if ($existing instanceof Application) {
            return new ProvisionResult($existing, created: false);
        }

        return DB::transaction(function (): ProvisionResult {
            $application = Application::query()->create([
                'name' => (string) config('cardpay.gateway.name', 'Default Store'),
                'slug' => $this->slug(),
                'public_key' => 'app_'.Str::lower(Str::random(32)),
                'webhook_url' => $this->cleanUrl(config('cardpay.gateway.webhook_url')),
                'callback_url' => $this->cleanUrl(config('cardpay.gateway.callback_url')),
                'is_active' => true,
                'token_digits' => (int) config('cardpay.token.digits', 3),
                'payment_expiration_minutes' => (int) config('cardpay.expiration_minutes', 30),
                // A card may not exist yet; the admin assigns one afterwards.
                'default_bank_card_id' => BankCard::query()->where('is_active', true)->value('id'),
            ]);

            $credentials = $this->issueApiKey($application);

            return new ProvisionResult($application, created: true, credentials: $credentials);
        });
    }

    /**
     * Mint a new key pair and retire every previous one. Rotation is immediate
     * and total: the old secret stops authenticating on the next request, so
     * this is also the recovery path when an install-time reveal was lost.
     */
    public function rotateApiKey(?Application $application = null, string $label = 'Primary'): GatewayCredentials
    {
        $application ??= $this->resolve();

        return DB::transaction(function () use ($application, $label): GatewayCredentials {
            ApplicationApiKey::query()
                ->where('application_id', $application->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'revoked_at' => now()]);

            return $this->issueApiKey($application, $label);
        });
    }

    /**
     * Insert a key pair, returning the plaintext secret to the caller. This is
     * the only moment it exists in the clear: the row stores the ciphertext
     * plus a fingerprint for constant-time verification (§SR-1).
     */
    private function issueApiKey(Application $application, string $label = 'Primary'): GatewayCredentials
    {
        $secret = Str::random(48);
        $publicKey = 'pk_'.Str::lower(Str::random(24));

        ApplicationApiKey::query()->create([
            'application_id' => $application->id,
            'public_key' => $publicKey,
            'secret_encrypted' => $secret,
            'secret_fingerprint' => Crypto::fingerprint($secret),
            'label' => $label,
            'is_active' => true,
        ]);

        return new GatewayCredentials($publicKey, $secret);
    }

    private function cleanUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
