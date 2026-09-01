<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Console;

use CartBecart\CardPay\Contracts\GatewayUser;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot installer for a host application: publishes the user-migrations
 * (users-table extension, passkeys, 2FA), runs them, verifies the host User
 * model satisfies the GatewayUser contract, and prints the remaining manual
 * steps. Re-running is safe: publishes are idempotent, migrations are
 * tracked, and the contract check is a pure read.
 */
final class InstallCommand extends Command
{
    protected $signature = 'cardpay:install {--force : Overwrite existing published migrations}';

    protected $description = 'Install CardPay into this application (publish user-migrations, migrate, verify setup)';

    public function handle(): int
    {
        $this->info('Installing CardPay…');

        $this->publishUserMigrations();
        $this->migrate();
        $this->verifyUserContract();

        $this->publishFortifyMigrations();
        $this->publishAssets();

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

    /**
     * Fortify's own migrations (2FA columns) are not published by default;
     * the passkeys/2FA flow needs them.
     */
    private function publishFortifyMigrations(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'two_factor_secret') && class_exists(\Laravel\Fortify\Fortify::class)) {
            $this->call('vendor:publish', ['--tag' => 'fortify-migrations']);
        }
    }

    private function publishAssets(): void
    {
        $this->call('vendor:publish', ['--tag' => 'cardpay-assets']);
    }

    private function migrate(): void
    {
        $this->info('Running migrations…');
        Artisan::call('migrate', ['--force' => true]);
        $this->line(trim(Artisan::output()));
    }

    /**
     * Loud, early verification of the ONE host code requirement: the user
     * model must implement GatewayUser (via the IsGatewayUser trait).
     */
    private function verifyUserContract(): void
    {
        $model = (string) config('cardpay.user.model', \App\Models\User::class);

        if (! class_exists($model)) {
            $this->error("cardpay.user.model class [$model] does not exist. Set CARDPAY_USER_MODEL or create the model.");

            return;
        }

        if (is_a($model, GatewayUser::class, true)) {
            $this->info("User model [$model] implements the GatewayUser contract.");

            return;
        }

        $this->error(
            "User model [$model] does NOT implement CartBecart\\CardPay\\Contracts\\GatewayUser.\n".
            "  Fix: add `use CartBecart\\CardPay\\Concerns\\IsGatewayUser;` inside your User model class\n".
            '  (and run the published user-migrations so role/is_active columns exist).'
        );
    }

    private function printChecklist(): void
    {
        $this->newLine();
        $this->info('CardPay install checklist — remaining manual steps:');
        $this->newLine();
        $this->line(' 1. Add `use CartBecart\CardPay\Concerns\IsGatewayUser;` to your User model (if not already done).');
        $this->line(' 2. Add the API exception renderer to bootstrap/app.php:');
        $this->line('        ->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions): void {');
        $this->line('            CartBecart\CardPay\Http\ApiExceptionRenderer::configure($exceptions);');
        $this->line('        })');
        $this->line(' 3. Set CARDPAY_* env vars (see config/cardpay.php) — or visit the /setup wizard.');
        $this->line(' 4. Visit /setup in the browser to complete installation.');
        $this->newLine();
    }
}
