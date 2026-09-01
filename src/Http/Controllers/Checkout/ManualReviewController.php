<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Checkout;

use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\Setting;
use CartBecart\CardPay\Services\Payments\ManualReviewReportService;
use CartBecart\CardPay\Services\RateLimiting\DbRateLimiter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Customer report endpoint `POST /p/{public_id}/manual-review` (§FR-8 #3).
 *
 * A session CSRF form (§SR-7) rate-limited to 5/hour per IP+payment (§A7).
 * `approximate_paid_at` is a local wall-clock value interpreted in the
 * configured display timezone and normalized to UTC for storage; an invalid
 * value is a 422, never a guess. Success redirects back with ?review=submitted.
 */
final class ManualReviewController extends Controller
{
    public function __construct(
        private readonly ManualReviewReportService $reports,
        private readonly DbRateLimiter $rateLimiter,
    ) {}

    public function store(Request $request, string $publicId): RedirectResponse
    {
        // 5/hour per IP+payment — tight, because each accepted report creates
        // admin work and stores an upload.
        $this->rateLimiter->hit('report', 'ippay:'.$request->ip().'|'.$publicId,
            (int) config('cardpay.rate_limits.report', 5), 3600);

        $payment = Payment::query()->where('public_id', $publicId)->first();

        if ($payment === null) {
            // Web surface: an unknown id is simply a 404 page, never a JSON
            // catalog envelope or generic 500 (§FR-8 parity with the page).
            throw new NotFoundHttpException;
        }

        try {
            $input = $this->validated($request);
            $receipt = $request->hasFile('receipt') ? $request->file('receipt') : null;
            $this->reports->report($payment, $input, $receipt, (string) $request->ip());
        } catch (ApiException $e) {
            // Surface catalog errors back on the form as a flag; the customer
            // never sees stack traces or internals (§SR-15).
            return redirect()
                ->to($this->backUrl($publicId, ['review' => 'error', 'reason' => $e->errorCode->value]));
        }

        return redirect()->to($this->backUrl($publicId, ['review' => 'submitted']));
    }

    /**
     * @return array{reported_amount?:int|null, approximate_paid_at?:\DateTimeInterface|null,
     *         contact_mobile?:string|null, customer_note?:string|null}
     *
     * @throws ApiException
     */
    private function validated(Request $request): array
    {
        $input = [];

        if ($request->filled('reported_amount')) {
            $amount = filter_var($request->input('reported_amount'), FILTER_VALIDATE_INT);

            if ($amount === false || $amount < 1) {
                throw ApiException::validation(['reported_amount' => 'must be a positive integer.']);
            }

            $input['reported_amount'] = $amount;
        }

        if ($request->filled('approximate_paid_at')) {
            try {
                // Wall-clock input in the configured display timezone → UTC.
                $input['approximate_paid_at'] = Carbon::parse(
                    (string) $request->input('approximate_paid_at'),
                    (string) Setting::get('timezone', 'Asia/Tehran'),
                )->utc();
            } catch (\Throwable) {
                throw ApiException::validation(['approximate_paid_at' => 'must be a valid date/time.']);
            }
        }

        if ($request->filled('contact_mobile')) {
            $mobile = trim((string) $request->input('contact_mobile'));
            if ($mobile !== '') {
                $input['contact_mobile'] = $mobile;
            }
        }

        if ($request->filled('customer_note')) {
            $note = trim((string) $request->input('customer_note'));
            if ($note !== '') {
                $input['customer_note'] = mb_substr($note, 0, 2000);
            }
        }

        return $input;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function backUrl(string $publicId, array $params = []): string
    {
        return $params === []
            ? '/p/'.$publicId
            : '/p/'.$publicId.'?'.http_build_query($params);
    }
}
