<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Facades;

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\CardPayManager;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for {@see CardPayManager} — the in-process gateway API.
 *
 * <code>
 * $payment = CardPay::createPayment([
 *     'amount' => 250_000,
 *     'external_order_id' => (string) $order->id,
 *     'customer' => ['name' => $order->name, 'mobile' => $order->mobile],
 *     'return_url' => route('orders.show', $order),
 * ], idempotencyKey: 'order-'.$order->id);
 *
 * return redirect($payment['payment_url']);
 * </code>
 *
 * @method static Application application()
 * @method static array<string, mixed> createPayment(array<string, mixed> $attributes, ?string $idempotencyKey = null)
 * @method static Payment find(string $publicId)
 * @method static array<string, mixed> status(string $publicId)
 * @method static bool isPaid(string $publicId)
 * @method static array<string, mixed> cancel(string $publicId)
 * @method static string checkoutUrl(string $publicId)
 *
 * @see CardPayManager
 */
final class CardPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cardpay';
    }
}
