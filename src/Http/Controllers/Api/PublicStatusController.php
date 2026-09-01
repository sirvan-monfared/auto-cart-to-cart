<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Api;

use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Maintenance\LazyMaintenance;
use CartBecart\CardPay\Services\RateLimiting\DbRateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public payment-status polling for the hosted checkout (§FR-8 #2).
 *
 * Unauthenticated BY DESIGN — the customer's browser polls it while the page
 * is open — so it is guarded three ways: a per-IP+payment rate limit, an
 * opaque `public_id` in the path (no enumerable numeric id), and a response
 * body restricted to the fields the checkout UI needs. It never exposes card
 * data or the amount token split.
 *
 * Each poll runs one budgeted maintenance slice FIRST (§FR-15): this is what
 * expires overdue payments without cron, so a customer who waits past expiry
 * sees `expired` without any worker existing.
 */
final class PublicStatusController extends Controller
{
    public function __construct(
        private readonly LazyMaintenance $maintenance,
        private readonly DbRateLimiter $rateLimiter,
    ) {}

    public function show(Request $request, string $publicId): JsonResponse
    {
        // §A7: per IP+payment fixed window before anything else.
        $this->rateLimit($request, $publicId);

        // Maintenance first so an overdue payment reads back as expired.
        $this->maintenance->runBudgeted();

        $payment = Payment::query()->where('public_id', $publicId)->first();

        if ($payment === null) {
            throw ApiException::paymentNotFound();
        }

        return response()
            ->json(['success' => true, 'data' => $this->present($payment)])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Payment $payment): array
    {
        return [
            'payment_id' => $payment->public_id,
            'status' => $payment->status->value,
            'payable_amount' => $payment->payable_amount,
            'currency' => $payment->currency,
            'expires_at' => $payment->expires_at->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'return_url' => $payment->return_url,
        ];
    }

    private function rateLimit(Request $request, string $publicId): void
    {
        $this->rateLimiter->hit(
            'public_status',
            'ippay:'.$request->ip().'|'.$publicId,
            (int) config('cardpay.rate_limits.public_status', 120),
        );
    }
}
