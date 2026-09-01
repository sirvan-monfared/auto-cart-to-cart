<?php

namespace CartBecart\CardPay\Database\Seeders;

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Services\Security\Crypto;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The default merchant application (slug `store`) with its first API key.
 *
 * Idempotent on slug. The API key is created ONLY when the application is
 * newly created — re-running never rotates or regenerates the live secret
 * (that would silently break the merchant's integration). When no bank card
 * exists yet, the app starts without a default card; the admin (or Setup)
 * assigns one later.
 */
class DefaultApplicationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $existing = Application::query()->where('slug', 'store')->first();

        if ($existing instanceof Application) {
            return;
        }

        $defaultCard = BankCard::query()->where('is_active', true)->first();

        $application = Application::query()->create([
            'name' => 'Default Store',
            'slug' => 'store',
            'public_key' => 'app_'.Str::lower(Str::random(32)),
            'is_active' => true,
            'token_digits' => 3,
            'payment_expiration_minutes' => 30,
            'default_bank_card_id' => $defaultCard?->id,
        ]);

        // First live credential; shown once at install/Setup, stored encrypted.
        $secret = Str::random(48);

        ApplicationApiKey::query()->create([
            'application_id' => $application->id,
            'public_key' => 'pk_'.Str::lower(Str::random(24)),
            'secret_encrypted' => $secret,
            'secret_fingerprint' => Crypto::fingerprint($secret),
            'label' => 'Primary',
            'is_active' => true,
        ]);
    }
}
