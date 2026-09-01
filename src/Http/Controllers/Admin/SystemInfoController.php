<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * System page (§FR-16): runtime versions, required extensions, writable
 * directories, and migration state — the diagnostics an operator needs when
 * something looks wrong. Pure reads; changes nothing.
 */
final class SystemInfoController extends Controller
{
    public function index(): View
    {
        return view('cardpay::admin.system', [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'server' => DB::getDriverName(),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'extensions' => collect(['openssl', 'pdo', 'mbstring', 'json', 'curl', 'gd'])
                ->map(fn ($ext) => ['name' => $ext, 'loaded' => extension_loaded($ext)]),
            'writable' => collect([
                storage_path('framework'),
                storage_path('logs'),
                storage_path('app'),
                base_path('bootstrap/cache'),
            ])->map(fn ($dir) => ['path' => $dir, 'ok' => is_writable($dir)]),
            'installed' => file_exists(storage_path('installed.lock')),
            'migrations' => $this->pendingMigrations(),
        ]);
    }

    /**
     * Migration files present on disk but absent from the migrations table.
     *
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (\Throwable) {
            return ['<migrations table unreadable>'];
        }

        $files = array_merge(
            glob(__DIR__.'/../../../Database/Migrations/*.php') ?: [],
            glob(database_path('migrations/*.php')) ?: [],
        );
        if ($files === []) {
            return [];
        }

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (! in_array($name, $ran, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }
}
