<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\AdminApi\Support\Present;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read access to the payment ledger.
 *
 * Deliberately read-only: money transitions belong to the matcher, the state
 * machine, and the review queue, all of which enforce the §9.2 rules. An admin
 * who could patch a payment status directly would bypass token release and
 * webhook emission, so there is no such endpoint — approving a review is the
 * supported way to settle a payment by hand.
 */
final class PaymentController extends Controller
{
    use RespondsWithJson;

    private const STATUSES = ['pending', 'paid', 'expired', 'canceled', 'rejected', 'manual_review'];

    public function index(Request $request): JsonResponse
    {
        $query = Payment::query();

        $status = (string) $request->string('status');
        if (in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        if (($orderId = trim((string) $request->string('external_order_id'))) !== '') {
            $query->where('external_order_id', $orderId);
        }

        if (($search = trim((string) $request->string('q'))) !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('public_id', 'like', "%{$search}%")
                    ->orWhere('customer_mobile', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        if (($from = $request->date('from')) !== null) {
            $query->where('created_at', '>=', $from);
        }

        if (($to = $request->date('to')) !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $this->page(
            $query->latest('id')->paginate($this->perPage($request))->withQueryString(),
            Present::payment(...),
        );
    }

    public function show(string $publicId): JsonResponse
    {
        $payment = Payment::query()
            ->where('public_id', $publicId)
            ->with(['bankCard', 'matches', 'reviews'])
            ->first();

        if (! $payment instanceof Payment) {
            throw new NotFoundHttpException('Payment not found.');
        }

        // Everything the matcher considered: the settling SMS plus any message
        // linked as evidence during review.
        $smsIds = $payment->matches
            ->pluck('incoming_sms_id')
            ->merge([$payment->matched_sms_id])
            ->filter()
            ->unique();

        return $this->ok(Present::paymentDetail(
            $payment,
            IncomingSms::query()->whereIn('id', $smsIds)->get(),
            $payment->webhookEvents()->with('deliveries')->get(),
        ));
    }
}
