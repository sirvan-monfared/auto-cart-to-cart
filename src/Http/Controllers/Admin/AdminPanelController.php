<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Enums\MatchStatus;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\AuditLog;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\WebhookDelivery;
use CartBecart\CardPay\Models\WebhookEvent;
use CartBecart\CardPay\Services\Webhooks\HttpWebhookProcessor;
use CartBecart\CardPay\Services\Webhooks\WebhookProcessor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-mostly admin panel pages (§FR-16), English LTR, one action per surface.
 * Every listing is paginated and ordered newest-first; the review queue page
 * carries the approve/reject form wired to ReviewAdminController; the webhook
 * monitor can force a manual retry. No money logic lives here.
 */
final class AdminPanelController extends Controller
{
    public function dashboard(): View
    {
        $byStatus = Payment::query()
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status');

        return view('cardpay::admin.dashboard', [
            'counts' => [
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'paid' => (int) ($byStatus['paid'] ?? 0),
                'expired' => (int) ($byStatus['expired'] ?? 0),
                'manual_review' => (int) ($byStatus['manual_review'] ?? 0),
                'canceled' => (int) ($byStatus['canceled'] ?? 0),
                'rejected' => (int) ($byStatus['rejected'] ?? 0),
            ],
            // Volume actually confirmed today (UTC day of `now`).
            'paidToday' => Payment::query()->where('status', 'paid')->where('paid_at', '>=', now()->startOfDay())->count(),
            'pendingReviews' => ManualReviewRequest::query()->where('status', 'pending')->count(),
            'unmatchedSms' => IncomingSms::query()->where('match_status', 'unmatched')->count(),
            'failedWebhooks' => WebhookDelivery::query()->whereIn('status', ['failed', 'exhausted'])->count(),
            'devices' => Device::query()->where('is_active', true)->count(),
        ]);
    }

    public function payments(Request $request): View
    {
        $query = Payment::query()->with('application:id,slug');

        $status = (string) $request->string('status');
        if (in_array($status, ['pending', 'paid', 'expired', 'canceled', 'rejected', 'manual_review'], true)) {
            $query->where('status', $status);
        }

        return view('cardpay::admin.payments', [
            'payments' => $query->latest('id')->paginate(25)->withQueryString(),
        ]);
    }

    public function paymentDetail(string $publicId): View
    {
        $payment = Payment::query()
            ->where('public_id', $publicId)
            ->with(['bankCard:id,title,bank_name,card_number_last_four', 'matches', 'reviews'])
            ->firstOrFail();

        $smsIds = $payment->matches->pluck('incoming_sms_id')->merge([$payment->matched_sms_id])->filter()->unique();

        return view('cardpay::admin.payment-detail', [
            'payment' => $payment,
            'smsEvidence' => IncomingSms::query()->whereIn('id', $smsIds)->get(),
            'webhookEvents' => $payment->webhookEvents()->with('deliveries')->get(),
        ]);
    }

    public function reviews(): View
    {
        return view('cardpay::admin.reviews', [
            'pending' => ManualReviewRequest::query()
                ->where('status', 'pending')
                ->with('payment:id,public_id,status,payable_amount,currency,bank_card_id')
                ->latest('id')
                ->paginate(20),
            'decided' => ManualReviewRequest::query()
                ->whereIn('status', ['approved', 'rejected'])
                ->latest('reviewed_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function smsLog(Request $request): View
    {
        $query = IncomingSms::query()->with('device:id,name');

        if ((string) $request->string('match') === 'unmatched') {
            $query->where('match_status', MatchStatus::Unmatched);
        }

        return view('cardpay::admin.sms-log', [
            'messages' => $query->latest('id')->paginate(30)->withQueryString(),
        ]);
    }

    public function webhooks(): View
    {
        return view('cardpay::admin.webhooks', [
            'events' => WebhookEvent::query()
                ->with(['application:id,slug', 'deliveries'])
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function retryWebhook(int $delivery): RedirectResponse
    {
        /** @var WebhookProcessor $processor */
        $processor = app(WebhookProcessor::class);

        $ok = $processor instanceof HttpWebhookProcessor && $processor->retry($delivery);

        return back()->with($ok ? 'webhook_requeued' : 'webhook_retry_failed', true);
    }

    public function auditLog(): View
    {
        return view('cardpay::admin.audit', [
            'entries' => AuditLog::query()->latest('id')->paginate(40),
        ]);
    }
}
