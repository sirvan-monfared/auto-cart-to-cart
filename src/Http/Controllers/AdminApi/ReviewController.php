<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Contracts\GatewayUser;
use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\AdminApi\Support\Present;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\Payments\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The manual review queue (§FR-12) — the one admin surface that moves money,
 * and therefore the one that most needs to stay a thin shell.
 *
 * Every rule lives in {@see ReviewService}: which source states may settle,
 * the conditional transition that decides a single winner, token release, and
 * webhook emission. This controller validates input, delegates, and reports.
 * ApiException from the service is rendered as the standard error envelope by
 * the handler, so a lost race returns a catalog code rather than a 500.
 */
final class ReviewController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly ReviewService $reviews,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->string('status', 'pending');

        $query = ManualReviewRequest::query()
            ->with('payment:id,public_id,status,payable_amount,currency,bank_card_id');

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        return $this->page(
            $query->latest('id')->paginate($this->perPage($request))->withQueryString(),
            Present::review(...),
        );
    }

    public function show(ManualReviewRequest $review): JsonResponse
    {
        return $this->ok(Present::review($review->load('payment')));
    }

    /**
     * Settle the payment behind this review. `sms_id` optionally links the
     * bank message that proves it, which is what makes the decision auditable
     * after the fact.
     */
    public function approve(Request $request, ManualReviewRequest $review): JsonResponse
    {
        $data = $request->validate([
            'sms_id' => ['nullable', 'integer', 'exists:cp_incoming_sms,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var GatewayUser $admin */
        $admin = $request->user();

        $decided = $this->reviews->approve(
            $review->id,
            $admin,
            smsId: isset($data['sms_id']) ? (int) $data['sms_id'] : null,
            note: $this->note($data['note'] ?? null),
        );

        $this->audit->log('review.approved', 'admin', $admin->getKey(), 'manual_review_request', (string) $review->id);

        return $this->ok(Present::review($decided->load('payment')));
    }

    public function reject(Request $request, ManualReviewRequest $review): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var GatewayUser $admin */
        $admin = $request->user();

        $decided = $this->reviews->reject($review->id, $admin, $this->note($data['note'] ?? null));

        $this->audit->log('review.rejected', 'admin', $admin->getKey(), 'manual_review_request', (string) $review->id);

        return $this->ok(Present::review($decided->load('payment')));
    }

    private function note(?string $value): ?string
    {
        $note = trim((string) $value);

        return $note !== '' ? $note : null;
    }
}
