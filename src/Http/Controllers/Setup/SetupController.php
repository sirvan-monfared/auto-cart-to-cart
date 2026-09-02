<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Setup;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Services\Setup\SetupService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The guided setup wizard (§FR-1), reachable only while the install lock is
 * absent (the `installed` middleware 404s it afterwards — §SR-16).
 */
final class SetupController extends Controller
{
    public function __construct(private readonly SetupService $setup) {}

    public function index(): View
    {
        $requirements = $this->setup->requirements();

        return view('cardpay::setup.index', [
            'requirements' => $requirements,
            'requirementsOk' => $this->setup->requirementsSatisfied($requirements),
            'dbMigrated' => $this->setup->databaseMigrated(),
            'hasAdmin' => $this->setup->hasActiveAdmin(),
            'hasHostUsers' => $this->setup->hasHostUsers(),
        ]);
    }

    public function installDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            'db_password' => ['nullable', 'string'],
        ]);

        $result = $this->setup->installDatabase(
            host: $data['db_host'],
            port: (int) $data['db_port'],
            database: $data['db_database'],
            username: $data['db_username'],
            password: (string) ($data['db_password'] ?? ''),
        );

        if ($result['ok'] === false) {
            return back()->with('setup_error', (string) ($result['error'] ?? 'Unknown setup failure.'));
        }

        return redirect()->to($this->setup->shouldSkipAdminStep()
            ? cardpay_setup_route('finalize')
            : cardpay_setup_route('admin'));
    }

    public function showAdmin(): View|RedirectResponse
    {
        if ($this->setup->shouldSkipAdminStep()) {
            return redirect()->to(cardpay_setup_route('finalize'));
        }

        return view('cardpay::setup.admin', [
            'hasAdmin' => $this->setup->hasActiveAdmin(),
            'hasHostUsers' => $this->setup->hasHostUsers(),
        ]);
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        if ($this->setup->shouldSkipAdminStep()) {
            return redirect()->to(cardpay_setup_route('finalize'));
        }

        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);

        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', Password::min(10), 'confirmed'],
            'username' => ['required', 'alpha_dash', 'min:3', 'max:190', 'unique:users,username'],
            'mobile' => ['nullable', 'string', 'max:30'],
        ]);

        $this->setup->createAdmin(
            name: (string) $request->input('name'),
            username: (string) $request->input('username'),
            mobile: (string) $request->input('mobile', ''),
            password: (string) $request->input('password'),
        );

        return redirect()->to(cardpay_setup_route('finalize'));
    }

    public function showFinalize(): View
    {
        return view('cardpay::setup.finalize');
    }

    public function finalize(Request $request): View
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:500'],
            'currency' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $result = $this->setup->finalize($validated);

        return view('cardpay::setup.done', $result);
    }
}
