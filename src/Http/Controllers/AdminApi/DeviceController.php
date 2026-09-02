<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\AdminApi\Support\Present;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\Security\Crypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Trusted relay devices (§FR-5): the phones or iOS Shortcuts that forward bank
 * deposit SMS, which is what makes matching automatic.
 *
 * A device secret is returned EXACTLY ONCE — in the create and rotate
 * responses. Afterwards only its ciphertext and SHA-256 fingerprint remain, so
 * there is no endpoint that can show it again; losing it means rotating.
 * Revocation is permanent by design: a phone that may have been compromised
 * must never be re-enabled by flipping a boolean.
 */
final class DeviceController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return $this->page(
            Device::query()->latest('id')->paginate($this->perPage($request)),
            Present::device(...),
        );
    }

    public function show(Device $device): JsonResponse
    {
        return $this->ok(Present::device($device));
    }

    public function store(Request $request): JsonResponse
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

        return $this->ok([
            ...Present::device($device),
            // The one and only reveal — surface it to the operator immediately.
            'secret' => $secret,
        ], 201);
    }

    public function update(Request $request, Device $device): JsonResponse
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

        return $this->ok(Present::device($device));
    }

    /** Mint a new key+secret pair; the previous pair stops working immediately. */
    public function rotate(Request $request, Device $device): JsonResponse
    {
        $secret = Str::random(48);

        $device->forceFill([
            'device_key' => 'dk_'.Str::lower(Str::random(24)),
            'device_secret_encrypted' => $secret,
            'secret_fingerprint' => Crypto::fingerprint($secret),
        ])->save();

        $this->audit->log('device.rotated', 'admin', $request->user()?->id, 'device', (string) $device->id);

        return $this->ok([
            ...Present::device($device),
            'secret' => $secret,
        ]);
    }

    /** Permanent: a revoked device can never relay SMS again. */
    public function revoke(Request $request, Device $device): JsonResponse
    {
        if ($device->revoked_at === null) {
            $device->forceFill(['revoked_at' => now(), 'is_active' => false])->save();

            $this->audit->log('device.revoked', 'admin', $request->user()?->id, 'device', (string) $device->id);
        }

        return $this->ok(Present::device($device));
    }
}
