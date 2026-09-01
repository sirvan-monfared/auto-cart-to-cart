<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\Security\Crypto;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Merchant application CRUD (§FR-3) with credential rotation (§SR-14).
 *
 * The API secret is shown EXACTLY ONCE — at creation or immediately after a
 * rotate — flashed through the session and never persisted in plaintext.
 * Rotation revokes the old key (never deletes: history and audit stay intact)
 * and mints a replacement. All actions audited with fingerprints only, never
 * secrets.
 */
final class ApplicationAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('cardpay::admin.applications', [
            'applications' => Application::query()->with('apiKeys')->latest('id')->paginate(20),
            // One-shot secret display (create/rotate) read then cleared.
            'revealedSecret' => session('revealed_secret'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'webhook_url' => ['nullable', 'url:http,https', 'max:500'],
            'callback_url' => ['nullable', 'url:http,https', 'max:500'],
            'allowed_domains' => ['nullable', 'string', 'max:2000'],
            'token_digits' => ['required', 'integer', 'in:2,3'],
            'payment_expiration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'default_bank_card_id' => ['nullable', 'integer', 'exists:cp_bank_cards,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $application = Application::query()->create([
            ...$data,
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
            'public_key' => 'app_'.Str::lower(Str::random(32)),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        [$key, $secret] = $this->mintKey($application);

        $this->audit->log('application.created', 'admin', $request->user()?->id, 'application', (string) $application->id,
            null, ['slug' => $application->slug]);

        return back()->with([
            'application_ok' => 'created',
            'revealed_secret' => ['public_key' => $key->public_key, 'secret' => $secret],
        ]);
    }

    /**
     * Rotate the application's credentials: revoke the current active key,
     * mint a fresh one. The new secret is revealed once via session flash.
     */
    public function rotate(Request $request, Application $application): RedirectResponse
    {
        ApplicationApiKey::query()
            ->where('application_id', $application->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'is_active' => false]);

        [$key, $secret] = $this->mintKey($application);

        $this->audit->log('application.key_rotated', 'admin', $request->user()?->id, 'application', (string) $application->id,
            null, ['new_key_fingerprint' => $key->secret_fingerprint]);

        return back()->with([
            'application_ok' => 'rotated',
            'revealed_secret' => ['public_key' => $key->public_key, 'secret' => $secret],
        ]);
    }

    public function update(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'webhook_url' => ['nullable', 'url:http,https', 'max:500'],
            'callback_url' => ['nullable', 'url:http,https', 'max:500'],
            'allowed_domains' => ['nullable', 'string', 'max:2000'],
            'token_digits' => ['required', 'integer', 'in:2,3'],
            'payment_expiration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'default_bank_card_id' => ['nullable', 'integer', 'exists:cp_bank_cards,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = ['is_active' => $application->is_active];
        $application->update([...$data, 'is_active' => (bool) ($data['is_active'] ?? true)]);

        $this->audit->log('application.updated', 'admin', $request->user()?->id, 'application', (string) $application->id,
            $old, ['is_active' => $application->is_active]);

        return back()->with('application_ok', 'updated');
    }

    /**
     * Mint a complete key row (secret + fingerprint in the SAME insert — the
     * column is NOT NULL) and return [key, plaintext-secret-for-one-time-show].
     *
     * @return array{0: ApplicationApiKey, 1: string}
     */
    private function mintKey(Application $application): array
    {
        $secret = Str::random(48);

        $key = ApplicationApiKey::query()->create([
            'application_id' => $application->id,
            'public_key' => 'pk_'.Str::lower(Str::random(24)),
            'secret_encrypted' => $secret,
            'secret_fingerprint' => Crypto::fingerprint($secret),
            'label' => 'Primary',
            'is_active' => true,
        ]);

        return [$key, $secret];
    }
}
