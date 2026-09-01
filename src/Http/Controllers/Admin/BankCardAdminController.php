<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Destination bank-card CRUD (§FR-4). The full card number and IBAN are
 * AES-256-GCM encrypted by the model's cast (§SR-1) — only the last four
 * digits are stored in clear for display. Create/update/activate are audited.
 */
final class BankCardAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('cardpay::admin.cards', [
            'cards' => BankCard::query()->with('smsParser:id,name')->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $card = BankCard::query()->create($data);

        $this->audit->log('card.created', 'admin', $request->user()?->id, 'bank_card', (string) $card->id,
            null, ['title' => $card->title, 'last_four' => $card->card_number_last_four]);

        return back()->with('card_ok', 'created');
    }

    public function update(Request $request, BankCard $card): RedirectResponse
    {
        $data = $this->validated($request, $card);

        $card->update($data);

        $this->audit->log('card.updated', 'admin', $request->user()?->id, 'bank_card', (string) $card->id,
            ['title' => $card->getOriginal('title')], ['title' => $card->title]);

        return back()->with('card_ok', 'updated');
    }

    public function destroy(Request $request, BankCard $card): RedirectResponse
    {
        // Soft-disable rather than delete: payments/SMS reference the card.
        $card->update(['is_active' => false]);

        $this->audit->log('card.deactivated', 'admin', $request->user()?->id, 'bank_card', (string) $card->id);

        return back()->with('card_ok', 'deactivated');
    }

    public function activate(Request $request, BankCard $card): RedirectResponse
    {
        // Re-enable a deactivated card. Idempotent: activating an already-active
        // card is a no-op and does not duplicate the audit entry.
        if (! $card->is_active) {
            $card->update(['is_active' => true]);

            $this->audit->log('card.activated', 'admin', $request->user()?->id, 'bank_card', (string) $card->id);
        }

        return back()->with('card_ok', 'activated');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?BankCard $existing = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'bank_name' => ['required', 'string', 'max:150'],
            'card_number' => [$existing === null ? 'required' : 'nullable', 'digits_between:12,24'],
            'card_holder_name' => ['required', 'string', 'max:190'],
            'iban' => ['nullable', 'string', 'max:34'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sms_parser_id' => ['nullable', 'integer', 'exists:cp_sms_parsers,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $out = [
            'title' => $data['title'],
            'bank_name' => $data['bank_name'],
            'card_holder_name' => $data['card_holder_name'],
            'description' => $data['description'] ?? $existing?->description,
            'sms_parser_id' => array_key_exists('sms_parser_id', $data) ? $data['sms_parser_id'] : $existing?->sms_parser_id,
            // When the edit form omits the flag, preserve the current state
            // instead of silently re-activating a deactivated card.
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
        ];

        // Only re-encrypt when a new value was actually supplied: the Encrypted
        // cast ciphers on write and never echoes the stored value back, so a
        // blank input means "keep what is stored" — and the column is left
        // untouched instead of being re-encrypted with a fresh IV.
        $digits = isset($data['card_number']) && trim((string) $data['card_number']) !== ''
            ? preg_replace('/\D/', '', $data['card_number'])
            : null;
        if ($digits !== null) {
            $out['card_number_encrypted'] = $digits;
            $out['card_number_last_four'] = substr($digits, -4);
        }

        $iban = isset($data['iban']) && trim((string) $data['iban']) !== ''
            ? preg_replace('/[^A-Za-z0-9]/', '', strtoupper($data['iban']))
            : null;
        if ($iban !== null) {
            $out['iban_encrypted'] = $iban;
        }

        return $out;
    }
}
