<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Enums\DeliveryStatus;
use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\AdminApi\Support\Present;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\WebhookDelivery;
use CartBecart\CardPay\Models\WebhookEvent;
use CartBecart\CardPay\Services\Webhooks\HttpWebhookProcessor;
use CartBecart\CardPay\Services\Webhooks\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Outbound webhook monitoring (§FR-13 / §A6).
 *
 * Delivery is driven by the budgeted lazy-maintenance layer rather than a
 * queue, so an endpoint that was down through the whole retry ladder ends up
 * `exhausted`. Retry is the manual escape hatch: it re-queues the delivery
 * while preserving the attempt history, so the failure record stays intact.
 */
final class WebhookController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $query = WebhookEvent::query()->with('deliveries');

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->integer('payment_id'));
        }

        return $this->page(
            $query->latest('id')->paginate($this->perPage($request))->withQueryString(),
            Present::webhookEvent(...),
        );
    }

    /** Deliveries across all events, for a "what is broken right now" view. */
    public function deliveries(Request $request): JsonResponse
    {
        $query = WebhookDelivery::query();

        if (($status = DeliveryStatus::tryFrom((string) $request->string('status'))) !== null) {
            $query->where('status', $status);
        }

        return $this->page(
            $query->latest('id')->paginate($this->perPage($request))->withQueryString(),
            Present::delivery(...),
        );
    }

    public function retry(WebhookDelivery $delivery): JsonResponse
    {
        /** @var WebhookProcessor $processor */
        $processor = app(WebhookProcessor::class);

        $requeued = $processor instanceof HttpWebhookProcessor && $processor->retry($delivery->id);

        return $this->ok([
            'requeued' => $requeued,
            'delivery' => Present::delivery($delivery->fresh() ?? $delivery),
        ]);
    }
}
