<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Drivers;

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Payments\TokenAllocator;
use RuntimeException;

/**
 * The first — and current — payment method (§5): manual card-to-card transfer
 * confirmed by unique-amount matching of the bank's deposit SMS.
 *
 * Uniqueness comes from the token allocator (§A1): the payable amount is the
 * original amount plus a CSPRNG token, unique per open payment per card by the
 * database's UNIQUE index. Confirmation is automatic via the matching engine,
 * which is why supportsAutomaticConfirmation() is true. Refunds are NOT
 * supported: there is no bank API to reverse a human-made transfer.
 */
final class CardTransferDriver implements PaymentDriver
{
    public function __construct(private readonly TokenAllocator $tokens) {}

    public function name(): string
    {
        return 'card_transfer';
    }

    public function label(): string
    {
        return 'Card to card transfer';
    }

    public function reserveAmount(BankCard $card, int $amount, Application $app): array
    {
        $reservation = $this->tokens->allocate($card->id, $amount, $app->token_digits);

        return [
            'token' => $reservation->token,
            'payable_amount' => $reservation->payable_amount,
        ];
    }

    public function releaseAmount(Payment $payment): void
    {
        // Start the cooldown so a late duplicate deposit can never rematch this
        // amount against a brand-new order (§A1 / §6 Cooldown).
        try {
            $this->tokens->cooldown($payment->id, (int) config('cardpay.cooldown_minutes', 10));
        } catch (RuntimeException) {
            // Cooldown failure must never disturb the caller's outcome.
        }
    }

    public function getPageData(Payment $payment): array
    {
        /** @var BankCard|null $card */
        $card = $payment->bankCard;

        return [
            'destination_card_number' => $card !== null ? (string) $card->getAttribute('card_number_encrypted') : '',
            'destination_card_holder' => $card !== null ? (string) $card->getAttribute('card_holder_name') : '',
            'destination_bank' => $card !== null ? (string) $card->getAttribute('bank_name') : '',
        ];
    }

    public function supportsAutomaticConfirmation(): bool
    {
        return true;
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(Payment $payment, int $amount): bool
    {
        unset($payment, $amount);

        return false; // no bank API exists to reverse a manual transfer
    }
}
