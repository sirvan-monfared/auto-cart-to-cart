<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Console;

use CartBecart\CardPay\Database\Seeders\DatabaseSeeder;
use CartBecart\CardPay\Services\Provisioning\GatewayProvisioner;
use CartBecart\CardPay\Support\Edition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * One-shot installer for a host application.
 *
 * Full hands off to the browser setup wizard after migrating. Lite has no
 * wizard and no panel, so this command IS the install: it migrates, seeds the
 * single gateway application, and prints its API credentials — the only time
 * the secret is ever visible (§16).
 */
final class InstallCommand extends Command
{
    protected $signature = 'cardpay:install
        {--force : Overwrite existing published migrations}
        {--no-seed : Skip seeding the gateway application and default parsers}';

    protected $description = 'Install CardPay into this application (publish migrations, migrate, seed, publish assets)';

    public function handle(GatewayProvisioner $provisioner): int
    {
        $this->info('Installing CardPay ('.Edition::current().' edition)…');

        $this->publishUserMigrations();
        $this->migrate();
        $this->publishAssets();

        if (! $this->option('no-seed')) {
            $this->seed($provisioner);
        }

        $this->printChecklist();

        return self::SUCCESS;
    }

    private function publishUserMigrations(): void
    {
        $this->call('vendor:publish', [
            '--tag' => 'cardpay-user-migrations',
            '--force' => (bool) $this->option('force'),
        ]);
    }

    /** Checkout needs the packaged CSS/JS/fonts in both editions. */
    private function publishAssets(): void
    {
        if (! Edition::enabled('checkout') && ! Edition::enabled('panel')) {
            return;
        }

        $this->call('vendor:publish', ['--tag' => 'cardpay-assets']);
    }

    private function migrate(): void
    {
        $this->info('Running migrations…');
        Artisan::call('migrate', ['--force' => true]);
        $this->line(trim(Artisan::output()));
    }

    /**
     * Seed defaults, then reveal the gateway credentials if this run created
     * them. Re-running never rotates a live secret, so a second install prints
     * nothing — use `cardpay:api-key:rotate` to recover a lost one.
     */
    private function seed(GatewayProvisioner $provisioner): void
    {
        $this->info('Seeding defaults…');

        Artisan::call('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ]);

        $result = $provisioner->provision();

        if ($result->credentials === null) {
            $this->line(" Gateway application [{$result->application->slug}] already exists — credentials unchanged.");

            return;
        }

        $this->newLine();
        $this->warn(' API credentials — shown ONCE, store them now:');
        $this->line('   Application key : '.$result->application->public_key);
        $this->line('   API public key  : '.$result->credentials->publicKey);
        $this->line('   API secret      : '.$result->credentials->secret);
        $this->newLine();
    }

    private function printChecklist(): void
    {
        $this->newLine();
        $this->info('CardPay install checklist — remaining manual steps:');
        $this->newLine();

        $this->line(' 1. Ensure your host authentication is configured (Fortify, Breeze, etc.).');
        $this->line(' 2. Define who may administer the gateway — override Gate::define(\'cardpay.access\', …) in AppServiceProvider if needed.');
        $this->line(' 3. Optionally adopt IsGatewayUser on your User model for role/is_active columns.');
        $this->line(' 4. Add the API exception renderer to bootstrap/app.php:');
        $this->line('        CartBecart\\CardPay\\Http\\ApiExceptionRenderer::configure($exceptions);');

        if (Edition::isLite()) {
            $this->printLiteChecklist();

            return;
        }

        $this->line(' 5. Set CARDPAY_PATH / CARDPAY_* env vars (see config/cardpay.php).');
        $this->line(' 6. Visit /'.cardpay_path().'/setup in the browser to complete installation.');
        $this->newLine();
    }

    private function printLiteChecklist(): void
    {
        $api = cardpay_admin_api_url();

        $this->line(' 5. Add a destination bank card and a relay device:');
        $this->line("        POST {$api}/cards");
        $this->line("        POST {$api}/devices");
        $this->line(' 6. Point the relay app / iOS Shortcut at:');
        $this->line('        POST /api/v1/devices/incoming-sms  (or /shortcut-sms)');
        $this->line(' 7. Create payments from your own code:');
        $this->line('        CardPay::createPayment([\'amount\' => 250000], idempotencyKey: \'order-1\');');
        $this->newLine();
        $this->info("This is the lite edition: no bundled panel. Build your admin on {$api}/*");
        $this->line('   Start with GET '.$api.'/features to discover what this install exposes.');
        $this->newLine();
    }
}
