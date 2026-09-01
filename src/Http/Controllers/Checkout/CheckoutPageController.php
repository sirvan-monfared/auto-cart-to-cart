<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Checkout;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Services\Maintenance\LazyMaintenance;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The hosted checkout page `GET /p/{public_id}` (§FR-8 #1).
 *
 * This is the CUSTOMER surface: Persian, RTL, branded from public settings.
 * Each view runs one budgeted maintenance slice (§FR-15) — page views are one
 * of the traffic sources that keep the gateway live without cron — then renders
 * the destination card number decrypted in memory for display only (never the
 * token split or any secret), with a status-aware UI and a strict no-store
 * policy so a shared/botched cache can never serve someone else's payment
 * context.
 */
final class CheckoutPageController extends Controller
{
    public function __construct(private readonly LazyMaintenance $maintenance) {}

    public function show(Request $request, string $publicId): Response
    {
        $this->maintenance->runBudgeted();

        $payment = Payment::query()
            ->where('public_id', $publicId)
            ->with('bankCard')
            ->first();

        if ($payment === null) {
            throw new NotFoundHttpException;
        }

        /** @var BankCard|null $card */
        $card = $payment->bankCard;

        return response()
            ->view('cardpay::checkout.show', [
                'payment' => $payment,
                'card' => $card,
                // §SR-1: decrypted only at render time; never logged, never cached.
                'cardNumber' => $card instanceof BankCard ? (string) $card->card_number_encrypted : '',
                'cardHolder' => $card instanceof BankCard ? $card->card_holder_name : '',
                'branding' => $this->branding(),
                'expiresInSeconds' => max(0, (int) now()->diffInSeconds($payment->expires_at, false)),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Public branding/texts (§14.2). Only rows flagged `is_public` may reach the
     * customer; every key has a safe fallback so a half-configured install still
     * renders a coherent page.
     *
     * @return array{title: string, help: string, success: string, expired: string, primary: string, accent: string}
     */
    private function branding(): array
    {
        return [
            'title' => (string) (Setting::get('payment_title', '') ?: 'پرداخت امن کارت به کارت'),
            'help' => (string) Setting::get('payment_help', ''),
            'success' => (string) (Setting::get('success_text', '') ?: 'پرداخت شما با موفقیت تأیید شد.'),
            'expired' => (string) (Setting::get('expired_text', '') ?: 'مهلت این پرداخت به پایان رسیده است.'),
            'primary' => (string) (Setting::get('primary_color', '#155EEF')),
            'accent' => (string) (Setting::get('accent_color', '#12B76A')),
        ];
    }
}
