<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Drivers;

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;

/**
 * A pluggable payment method (§5 / §16): the strategy behind how a payment is
 * created, presented to the customer, confirmed, and cancelled.
 *
 * Adding a future gateway type (e.g. an actual bank API) must not require
 * changing controllers or the state machine — implementations plug in through
 * the DriverRegistry and are selected by configuration alone.
 *
 * Implementations hold NO transaction logic of their own: creation composes
 * inside the caller's single transaction so partial creates can never persist,
 * and every confirmation path still funnels through the conditional
 * state-machine transitions that make concurrent writes fail-safe.
 */
interface PaymentDriver
{
    /** The stable machine name stored on payments.driver (e.g. 'card_transfer'). */
    public function name(): string;

    /**
     * Human-facing label for admin surfaces.
     */
    public function label(): string;

    /**
     * Reserve whatever makes this payment uniquely identifiable for matching
     * (for card transfer: a unique payable amount via the token allocator) and
     * return the token + payable amount to persist. Called INSIDE the create
     * transaction; throwing here rolls the whole create back.
     *
     * @return array{token: int, payable_amount: int}
     */
    public function reserveAmount(BankCard $card, int $amount, Application $app): array;

    /**
     * Release/unblock a payment's reserved identity after it leaves `pending`
     * (card transfer: start the cooldown). Must never throw into the caller.
     */
    public function releaseAmount(Payment $payment): void;

    /**
     * The §FR-8 page-data payload for the hosted checkout beyond the base
     * fields (card transfer: destination card number + holder for display).
     *
     * @return array<string, mixed>
     */
    public function getPageData(Payment $payment): array;

    /**
     * Whether this method can confirm itself without human/bank involvement
     * (card transfer: true — SMS matching does it automatically).
     */
    public function supportsAutomaticConfirmation(): bool;

    /** Whether refunds are possible through this method (§FR contract). */
    public function supportsRefund(): bool;

    /**
     * Attempt a refund. Card transfer has no bank API to reverse a manual
     * transfer, so the default implementation reports unsupported; real
     * gateway drivers implement the reversal here.
     */
    public function refund(Payment $payment, int $amount): bool;
}
