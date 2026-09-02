<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\WebhookDelivery;
use CartBecart\CardPay\Support\Edition;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard, as data: the same counters the panel renders, for a host that
 * draws its own. Everything here is a count — cheap enough to poll.
 */
final class OverviewController extends Controller
{
    use RespondsWithJson;

    public function stats(): JsonResponse
    {
        $byStatus = Payment::query()
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status');

        return $this->ok([
            'payments' => [
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'paid' => (int) ($byStatus['paid'] ?? 0),
                'expired' => (int) ($byStatus['expired'] ?? 0),
                'manual_review' => (int) ($byStatus['manual_review'] ?? 0),
                'canceled' => (int) ($byStatus['canceled'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
            ],
            // Confirmed volume for the current UTC day.
            'paid_today' => Payment::query()
                ->where('status', 'paid')
                ->where('paid_at', '>=', now()->startOfDay())
                ->count(),
            'pending_reviews' => ManualReviewRequest::query()->where('status', 'pending')->count(),
            'unmatched_sms' => IncomingSms::query()->where('match_status', 'unmatched')->count(),
            'failed_webhooks' => WebhookDelivery::query()->whereIn('status', ['failed', 'exhausted'])->count(),
            'active_devices' => Device::query()->where('is_active', true)->count(),
        ]);
    }

    /**
     * What this install actually exposes. A host admin should render its menu
     * from this rather than hardcoding assumptions about the edition.
     */
    public function features(): JsonResponse
    {
        return $this->ok([
            'edition' => Edition::current(),
            'features' => Edition::all(),
            'currency' => (string) config('cardpay.currency', 'IRR'),
            'driver' => (string) config('cardpay.driver', 'card_transfer'),
        ]);
    }
}
