<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services;

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Payments\PaymentService;
use CartBecart\CardPay\Services\Provisioning\GatewayProvisioner;
use Illuminate\Support\Str;

/**
 * In-process entry point to the gateway — the same engine the HMAC merchant
 * API drives, minus the HTTP round trip and the signing.
 *
 * This is how a single-site install is meant to take payments (§16 lite): your
 * checkout controller calls CardPay::createPayment() directly, so there are no
 * API keys to manage, no clock skew, no nonce ledger, and the payment can be
 * created inside the SAME database transaction as the order it belongs to.
 * The HMAC API remains for callers outside this application.
 *
 * The target application is resolved implicitly from the configured gateway
 * slug, so nothing in host code has to know that applications exist.
 */
final class CardPayManager
{
    /** Namespace for in-process idempotency keys. See {@see idempotencyKey()}. */
    private const KEY_PREFIX = 'cardpay:';

    public function __construct(
        private readonly PaymentService $payments,
        private readonly GatewayProvisioner $gateway,
    ) {}

    /** The application every in-process call is scoped to. */
    public function application(): Application
    {
        return $this->gateway->resolve();
    }

    /**
     * Create a pending payment and return the §FR-7 presentment: payment_id,
     * payable_amount (the amount the customer must transfer EXACTLY), token,
     * payment_url, expires_at.
     *
     * Supported keys: amount (int, minor units, required), bank_card_id,
     * external_order_id, description, customer[name|mobile|reference],
     * return_url, callback_url, metadata.
     *
     * Pass an $idempotencyKey derived from your order (e.g. "order-1042") to
     * make retries safe: a repeat with the same key replays the original
     * response instead of allocating a second amount token. When omitted a
     * random key is generated, which makes the call non-replayable — fine for
     * a user-initiated checkout, wrong for a job that may be retried.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createPayment(array $attributes, ?string $idempotencyKey = null): array
    {
        return $this->payments
            ->create($this->application(), $attributes, $this->idempotencyKey($idempotencyKey))
            ->data;
    }

    /**
     * The payment model, scoped to this gateway. Throws the catalog's
     * payment_not_found for an unknown id.
     */
    public function find(string $publicId): Payment
    {
        return $this->payments->find($this->application(), $publicId);
    }

    /**
     * Current state as the merchant API would present it — the value to poll
     * or to render on an order page.
     *
     * @return array<string, mixed>
     */
    public function status(string $publicId): array
    {
        return $this->payments->present($this->find($publicId), replay: false);
    }

    /** Whether the payment has settled. */
    public function isPaid(string $publicId): bool
    {
        return $this->find($publicId)->status->value === 'paid';
    }

    /**
     * Cancel a pending payment (e.g. the customer abandoned the order). Only
     * pending → canceled is legal; anything else raises the catalog error.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $publicId): array
    {
        return $this->payments->cancel($this->application(), $publicId)->data;
    }

    /** The hosted checkout URL for a payment. */
    public function checkoutUrl(string $publicId): string
    {
        return rtrim((string) config('app.url'), '/').'/p/'.$publicId;
    }

    /**
     * Turn a caller's key into one the ledger accepts.
     *
     * The merchant API requires 8–190 characters because it is a client-supplied
     * HTTP header; that is a transport rule, and letting it reject a perfectly
     * sensible in-process key like "order-7" would be an API leaking its wire
     * format. Prefixing fixes the length deterministically — the same input
     * always yields the same key, so replay semantics are untouched — and it
     * namespaces in-process keys away from HTTP ones, so an order id used on
     * both paths cannot collide.
     *
     * A key long enough to overflow the column collapses to its hash, which
     * stays deterministic and comfortably inside the bounds.
     */
    private function idempotencyKey(?string $key): string
    {
        $key = trim((string) $key);

        if ($key === '') {
            return self::KEY_PREFIX.'auto:'.Str::ulid();
        }

        $namespaced = self::KEY_PREFIX.$key;
        $min = (int) config('cardpay.idempotency.key_min', 8);
        $max = (int) config('cardpay.idempotency.key_max', 190);
        $length = strlen($namespaced);

        return $length >= $min && $length <= $max
            ? $namespaced
            : self::KEY_PREFIX.hash('sha256', $key);
    }
}
