<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Payments;

use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;
use Illuminate\Support\Facades\DB;

/**
 * Lazy expiration sweep (§FR-14). A pending payment past its `expires_at` is
 * moved to `expired` — but only via the same conditional transition the matcher
 * uses, so a deposit SMS that confirms a payment in the same instant always
 * wins cleanly and the expiry simply no-ops on that row (no double handling, no
 * spurious `payment.expired` after a `payment.paid`).
 *
 * Runs in small oldest-first batches (20 per lazy request, §A9) so the system
 * stays live under ordinary traffic without any cron or queue. Each row is its
 * own short transaction; the webhook is emitted only after that row commits.
 */
final class PaymentExpiryService
{
    public function __construct(
        private readonly PaymentStateMachine $stateMachine,
        private readonly TokenAllocator $allocator,
        private readonly WebhookEmitter $emitter,
    ) {}

    /**
     * Expire up to $limit pending payments whose window has closed, oldest
     * expiry first. Returns the number actually expired (races excluded).
     */
    public function expireDue(int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $due = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $expired = 0;

        foreach ($due as $payment) {
            $won = false;

            DB::transaction(function () use ($payment, &$won) {
                $won = $this->stateMachine->transition($payment, PaymentStatus::Expired);

                if ($won) {
                    // Hold the amount for the cooldown so a late deposit SMS can't
                    // match a brand-new order that reuses this amount (§FR-14).
                    $this->allocator->cooldown($payment->id, $this->cooldownMinutes());
                }
            });

            if ($won) {
                $this->emitter->emit($payment, WebhookEventType::Expired);
                $expired++;
            }
        }

        return $expired;
    }

    private function cooldownMinutes(): int
    {
        return (int) config('cardpay.cooldown_minutes', 10);
    }
}
