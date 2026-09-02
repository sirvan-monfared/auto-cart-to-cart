<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\AdminApi\Support\Present;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Destination bank cards (§FR-4) — the accounts customers transfer to.
 *
 * The PAN and IBAN are AES-256-GCM encrypted by the model casts (§SR-1); only
 * the last four digits are stored in the clear. Deletion is a soft disable
 * because payments and SMS reference the card: destroying the row would orphan
 * the evidence trail for every past transfer.
 */
final class BankCardController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return $this->page(
            BankCard::query()->latest('id')->paginate($this->perPage($request)),
            fn (BankCard $card): array => Present::card($card),
        );
    }

    public function show(BankCard $card): JsonResponse
    {
        return $this->ok(Present::card($card, withNumber: true));
    }

    public function store(Request $request): JsonResponse
    {
        $card = BankCard::query()->create($this->validated($request));

        $this->audit->log('card.created', 'admin', $request->user()?->id, 'bank_card', (string) $card->id,
            null, ['title' => $card->title, 'last_four' => $card->card_number_last_four]);

        return $this->ok(Present::card($card), 201);
    }

    public function update(Request $request, BankCard $card): JsonResponse
    {
        $card->update($this->validated($request, $card));

        $this->audit->log('card.updated', 'admin', $request->user()?->id, 'bank_card', (string) $card->id,
            ['title' => $card->getOriginal('title')], ['title' => $card->title]);

        return $this->ok(Present::card($card));
    }

    /** Soft-disable: the card stops accepting new payments, history survives. */
    public function destroy(Request $request, BankCard $card): JsonResponse
    {
        $card->update(['is_active' => false]);

        $this->audit->log('card.deactivated', 'admin', $request->user()?->id, 'bank_card', (string) $card->id);

        return $this->ok(Present::card($card));
    }

    /** Idempotent: re-activating an active card is a no-op, not a second audit entry. */
    public function activate(Request $request, BankCard $card): JsonResponse
    {
        if (! $card->is_active) {
            $card->update(['is_active' => true]);

            $this->audit->log('card.activated', 'admin', $request->user()?->id, 'bank_card', (string) $card->id);
        }

        return $this->ok(Present::card($card));
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
            // An omitted flag preserves the current state rather than silently
            // re-activating a card an admin deliberately disabled.
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
        ];

        // Only re-encrypt when a new value actually arrived: the Encrypted cast
        // never echoes the stored value back, so a blank input means "keep it"
        // and the column is left alone instead of re-ciphered with a fresh IV.
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
