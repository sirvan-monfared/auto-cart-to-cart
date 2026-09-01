<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\Security\Crypto;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Trusted relay device CRUD (§FR-5): bind to one bank card, secret shown once
 * at creation or rotation, revoke = permanent (§SR-14 audit throughout).
 * Shortcut mode verifies against the stored SHA-256 fingerprint, so the
 * plaintext secret is never needed again after the reveal moment.
 */
final class DeviceAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('cardpay::admin.devices', [
            'devices' => Device::query()->with('bankCard:id,title,bank_name')->latest('id')->paginate(20),
            'cards' => BankCard::query()->where('is_active', true)->orderBy('title')->get(['id', 'title', 'bank_name']),
            'revealedSecret' => session('revealed_secret'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'platform' => ['required', 'in:android,ios-shortcut'],
            'bank_card_id' => ['required', 'integer', 'exists:cp_bank_cards,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $secret = Str::random(48);

        $device = Device::query()->create([
            ...$data,
            'device_key' => 'dk_'.Str::lower(Str::random(24)),
            'device_secret_encrypted' => $secret,
            'secret_fingerprint' => Crypto::fingerprint($secret),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->audit->log('device.created', 'admin', $request->user()?->id, 'device', (string) $device->id,
            null, ['name' => $device->name, 'card_id' => $device->bank_card_id]);

        return back()->with([
            'device_ok' => 'created',
            // Shown once; gone on the next request.
            'revealed_secret' => ['device_key' => $device->device_key, 'secret' => $secret],
        ]);
    }

    /** Mint a new key+secret pair for the device (old pair dies immediately). */
    public function rotate(Request $request, Device $device): RedirectResponse
    {
        $secret = Str::random(48);

        $device->forceFill([
            'device_key' => 'dk_'.Str::lower(Str::random(24)),
            'device_secret_encrypted' => $secret,
            'secret_fingerprint' => Crypto::fingerprint($secret),
        ])->save();

        $this->audit->log('device.rotated', 'admin', $request->user()?->id, 'device', (string) $device->id);

        return back()->with([
            'device_ok' => 'rotated',
            'revealed_secret' => ['device_key' => $device->device_key, 'secret' => $secret],
        ]);
    }

    /** Revoke: permanent — a revoked device can never relay SMS again. */
    public function revoke(Request $request, Device $device): RedirectResponse
    {
        if ($device->revoked_at === null) {
            $device->forceFill([
                'revoked_at' => now(),
                'is_active' => false,
            ])->save();

            $this->audit->log('device.revoked', 'admin', $request->user()?->id, 'device', (string) $device->id);
        }

        return back()->with('device_ok', 'revoked');
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'bank_card_id' => ['required', 'integer', 'exists:cp_bank_cards,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $oldCard = $device->bank_card_id;
        $device->update([...$data, 'is_active' => (bool) ($data['is_active'] ?? true)]);

        $this->audit->log('device.updated', 'admin', $request->user()?->id, 'device', (string) $device->id,
            ['bank_card_id' => $oldCard], ['bank_card_id' => $device->bank_card_id]);

        return back()->with('device_ok', 'updated');
    }
}
