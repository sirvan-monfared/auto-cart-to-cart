<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Setup;

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Services\Security\Crypto;
use CartBecart\CardPay\Support\GatewayUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The guided installer (§FR-1), adapted SAFELY per the project decision:
 *
 *   • NO .env rewrite and NO APP_KEY regeneration — the existing environment
 *     (and every secret already encrypted under it) stays untouched;
 *   • migrations and idempotent seeders run only when their tables are absent,
 *     so re-running a half-completed wizard can never double-install;
 *   • the admin account is created once; if an active admin already exists the
 *     step is skipped rather than overwritten;
 *   • the default `store` application mints its API key exactly once;
 *   • storage/installed.lock is written LAST — a crash at any earlier step
 *     leaves the wizard reachable to finish, never a locked half-install.
 */
final class SetupService
{
    public function isInstalled(): bool
    {
        return file_exists(storage_path('installed.lock'));
    }

    /**
     * Step 1 diagnostics: what this environment can and cannot do.
     *
     * @return array{php_ok: bool, php_version: string, extensions: list<array{name: string, ok: bool}>, writable: list<array{path: string, ok: bool}>, app_key_set: bool}
     */
    public function requirements(): array
    {
        $extensions = [];
        foreach (['pdo', 'mbstring', 'openssl', 'json', 'curl'] as $ext) {
            $extensions[] = ['name' => $ext, 'ok' => extension_loaded($ext)];
        }

        $writable = [];
        foreach ([storage_path(), storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $writable[] = ['path' => $dir, 'ok' => is_writable($dir)];
        }

        return [
            'php_ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'php_version' => PHP_VERSION,
            'extensions' => $extensions,
            'writable' => $writable,
            // §SR-2: the key must ALREADY exist in .env — we never generate one.
            'app_key_set' => (string) config('app.key') !== '',
        ];
    }

    /**
     * Whether all step-1 checks pass.
     *
     * @param  array{php_ok: bool, extensions: list<array{ok: bool}>, writable: list<array{ok: bool}>, app_key_set: bool}  $requirements
     */
    public function requirementsSatisfied(array $requirements): bool
    {
        foreach ($requirements['extensions'] as $e) {
            if (! $e['ok']) {
                return false;
            }
        }
        foreach ($requirements['writable'] as $d) {
            if (! $d['ok']) {
                return false;
            }
        }

        return $requirements['php_ok'] && $requirements['app_key_set'];
    }

    /** Whether any migration table has been created yet (DB untouched?). */
    public function databaseMigrated(): bool
    {
        try {
            return Schema::hasTable('cp_settings');
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether an active admin account already exists (adapt-safely skip). */
    public function hasActiveAdmin(): bool
    {
        try {
            return Schema::hasTable('users')
                && GatewayUsers::hasActiveAdmin();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Step 2: test the connection with real credentials, then migrate +
     * seed. The credentials are written to the runtime config ONLY (never to
     * .env); the operator updates .env separately per adapt-safely policy.
     *
     * @return array{ok: bool, error?: string} `error` present only when ok is false.
     *
     * @phpstan-return array{ok: bool, error?: string}
     */
    public function installDatabase(string $host, int $port, string $database, string $username, string $password): array
    {
        // Probe the connection FIRST with a throwaway config; nothing migrates
        // unless this exact server+db answers.
        $probe = config('database.connections.mysql');
        $probe['host'] = $host;
        $probe['port'] = $port;
        $probe['database'] = $database;
        $probe['username'] = $username;
        $probe['password'] = $password;

        config(['database.connections.setup_probe' => $probe]);

        try {
            DB::connection('setup_probe')->getPdo();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Connection failed: '.$e->getMessage()];
        }

        // Point the DEFAULT connection at the verified target for this request
        // so the migrator/seeders run against exactly the probed database.
        config(['database.connections.mysql.host' => $host]);
        config(['database.connections.mysql.port' => $port]);
        config(['database.connections.mysql.database' => $database]);
        config(['database.connections.mysql.username' => $username]);
        config(['database.connections.mysql.password' => $password]);
        DB::purge('mysql');

        try {
            $this->runMigrations();
            $this->runSeeders();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Migration/seeding failed: '.$e->getMessage()];
        }

        return ['ok' => true];
    }

    private function runMigrations(): void
    {
        if ($this->databaseMigrated()) {
            return; // already migrated — never re-run on an existing schema
        }

        \Artisan::call('migrate', ['--force' => true]);
    }

    private function runSeeders(): void
    {
        // Idempotent by design (firstOrCreate / one-time key mint).
        \Artisan::call('db:seed', ['--force' => true]);
    }

    /**
     * Step 3: create THE admin. Refuses silently-by-skip when one exists so a
     * replayed step can never overwrite credentials.
     *
     * @return array{created: bool, username: string}
     */
    public function createAdmin(string $name, string $username, ?string $mobile, string $password): array
    {
        $username = Str::lower(trim($username));

        if ($this->hasActiveAdmin()) {
            return ['created' => false, 'username' => $username];
        }

        GatewayUsers::query()->create([
            'name' => $name,
            'username' => $username,
            'email' => $username.'@'.parse_url((string) config('app.url'), PHP_URL_HOST).'.local',
            'mobile' => $mobile !== '' ? $mobile : null,
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => $password,
        ]);

        return ['created' => true, 'username' => $username];
    }

    /**
     * Step 4: apply store settings over the seeded defaults, create the
     * default application (+ one-time key), then write the lock LAST.
     *
     * @param  array{title?:string, currency?:string, timezone?:string, primary_color?:string, accent_color?:string}  $settings
     * @return array{application_created: bool, public_key: string|null, secret: string|null}
     */
    public function finalize(array $settings): array
    {
        $map = [
            'title' => ['payment_title', 'string', true],
            'currency' => ['currency', 'string', true],
            'timezone' => ['timezone', 'string', true],
            'primary_color' => ['primary_color', 'string', true],
            'accent_color' => ['accent_color', 'string', true],
        ];

        foreach ($map as $input => [$key, $type, $isPublic]) {
            if (isset($settings[$input]) && trim((string) $settings[$input]) !== '') {
                Setting::put($key, trim((string) $settings[$input]), $type, $isPublic);
            }
        }

        $result = ['application_created' => false, 'public_key' => null, 'secret' => null];

        $existing = Application::query()->where('slug', 'store')->first();
        if ($existing === null) {
            $card = BankCard::query()->where('is_active', true)->first();

            $application = Application::query()->create([
                'name' => 'Default Store',
                'slug' => 'store',
                'public_key' => 'app_'.Str::lower(Str::random(32)),
                'is_active' => true,
                'token_digits' => 3,
                'payment_expiration_minutes' => 30,
                'default_bank_card_id' => $card?->id,
            ]);

            $secret = Str::random(48);
            ApplicationApiKey::query()->create([
                'application_id' => $application->id,
                'public_key' => 'pk_'.Str::lower(Str::random(24)),
                'secret_encrypted' => $secret,
                'secret_fingerprint' => Crypto::fingerprint($secret),
                'label' => 'Primary',
                'is_active' => true,
            ]);

            $apiKey = $application->apiKeys()->latest('id')->first();

            $result = [
                'application_created' => true,
                'public_key' => (string) $application->getAttribute('public_key'),
                'api_public_key' => $apiKey !== null ? (string) $apiKey->getAttribute('public_key') : null,
                'secret' => $secret,
            ];
        } elseif ($existing instanceof Application) {
            $result['public_key'] = (string) $existing->getAttribute('public_key');
        }

        // Lock LAST: everything above succeeded before the door closes.
        if (! @touch(storage_path('installed.lock'))) {
            // Lock failure must be loud — otherwise the installer stays open.
            throw new \RuntimeException('Could not write installed.lock — check directory permissions.');
        }

        return $result;
    }
}
