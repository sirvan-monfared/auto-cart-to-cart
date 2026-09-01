<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Contracts\GatewayUser;
use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\Payments\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin decisions on the review queue (§FR-12). Thin HTTP shell: validation
 * and money rules live in {@see ReviewService}; every decision is audited with
 * the acting admin (§SR-14). Catalog errors flash back to the queue page.
 */
final class ReviewAdminController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(Request $request, int $review): RedirectResponse
    {
        /** @var GatewayUser $admin */
        $admin = $request->user();

        try {
            $this->reviews->approve(
                $review,
                $admin,
                smsId: $this->optionalId($request->input('sms_id')),
                note: $this->optionalNote($request->input('note')),
            );
        } catch (ApiException $e) {
            return back()->with('decision_error', $e->errorCode->value);
        }

        $this->audit->log('review.approved', 'admin', $admin->getKey(), 'manual_review_request', (string) $review);

        return back()->with('decision_ok', 'approved');
    }

    public function reject(Request $request, int $review): RedirectResponse
    {
        /** @var GatewayUser $admin */
        $admin = $request->user();

        try {
            $this->reviews->reject($review, $admin, $this->optionalNote($request->input('note')));
        } catch (ApiException $e) {
            return back()->with('decision_error', $e->errorCode->value);
        }

        $this->audit->log('review.rejected', 'admin', $admin->getKey(), 'manual_review_request', (string) $review);

        return back()->with('decision_ok', 'rejected');
    }

    private function optionalId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false ? null : $id;
    }

    private function optionalNote(mixed $value): ?string
    {
        $note = trim((string) ($value ?? ''));

        return $note !== '' ? mb_substr($note, 0, 2000) : null;
    }
}
