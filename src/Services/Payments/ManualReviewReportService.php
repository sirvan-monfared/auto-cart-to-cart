<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Payments;

use CartBecart\CardPay\Enums\ApiErrorCode;
use CartBecart\CardPay\Enums\PaymentStatus;
use CartBecart\CardPay\Enums\WebhookEventType;
use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Services\Webhooks\WebhookEmitter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Customer-submitted manual review (§FR-8 #3): a payer whose transfer wasn't
 * recognised can report it with evidence, moving the payment into the human
 * queue.
 *
 * Guarantees, in order of importance:
 *   • the payment must be reviewable (`pending|expired|manual_review`) — a
 *     terminal payment can never be dragged back (§FR-12 guard);
 *   • ONE pending review per payment — repeated reports update/return the
 *     existing request instead of flooding the queue;
 *   • the receipt is stored OUTSIDE the public root under an unguessable
 *     random name, with server-side MIME sniffing against an allow-list
 *     (§SR-9) — never trusting the client's declared type;
 *   • the `payment.manual_review` event is emitted only AFTER commit.
 */
final class ManualReviewReportService
{
    public function __construct(
        private readonly PaymentStateMachine $stateMachine,
        private readonly WebhookEmitter $emitter,
    ) {}

    /**
     * @param  array{reported_amount?:int|null, approximate_paid_at?:\DateTimeInterface|null,
     *         contact_mobile?:string|null, customer_note?:string|null}  $input
     *
     * @throws ApiException `payment_not_found` / `payment_not_reviewable` /
     *                      `validation_failed` (bad upload or fields).
     */
    public function report(Payment $payment, array $input, ?UploadedFile $receipt, string $sourceIp): ManualReviewRequest
    {
        if (! in_array($payment->status->value, ['pending', 'expired', 'manual_review'], true)) {
            throw ApiException::paymentNotReviewable();
        }

        // Validate + store the upload BEFORE any state change: a rejected or
        // failed receipt must never leave the payment half-transitioned.
        $receiptPath = $receipt !== null ? $this->storeReceipt($receipt) : null;

        try {
            $saved = DB::transaction(function () use ($payment, $input, $receiptPath): ManualReviewRequest {
                // Dedupe INSIDE the transaction: one pending review per payment.
                // A repeat reporter edits the same queue entry rather than
                // spawning duplicates (§FR-8 #3).
                $review = ManualReviewRequest::query()
                    ->where('payment_id', $payment->id)
                    ->where('status', 'pending')
                    ->first();

                if ($review === null) {
                    // Move pending|expired → manual_review; losing the race to a
                    // concurrent writer is fine — the review still records the
                    // evidence, and no payment was guessed into another state.
                    $this->stateMachine->transition($payment, PaymentStatus::ManualReview);
                }

                $attributes = [
                    'reported_amount' => $input['reported_amount'] ?? null,
                    'approximate_paid_at' => $input['approximate_paid_at'] ?? null,
                    // Defaults to the payment's own customer mobile when absent.
                    'contact_mobile' => $input['contact_mobile'] ?? $payment->customer_mobile,
                    'customer_note' => $input['customer_note'] ?? null,
                ];

                if ($review instanceof ManualReviewRequest) {
                    // Only overwrite fields the customer re-supplied; keep any
                    // previously uploaded receipt unless a new one lands.
                    $review->fill(array_filter($attributes, fn ($v) => $v !== null));
                    if ($receiptPath !== null) {
                        $this->deleteReceipt($review->receipt_path);
                        $review->receipt_path = $receiptPath;
                    }
                    $review->save();

                    return $review;
                }

                return ManualReviewRequest::query()->create([
                    ...$attributes,
                    'payment_id' => $payment->id,
                    'receipt_path' => $receiptPath,
                    'status' => 'pending',
                ]);
            });
        } catch (Throwable $e) {
            // Never orphan an uploaded file when persistence fails.
            if ($receiptPath !== null) {
                $this->deleteReceipt($receiptPath);
            }

            throw $e;
        }

        // §FR-8 #3: the customer report emits payment.manual_review — only after
        // commit, so a rollback can never have produced an event.
        $this->emitter->emit($payment, WebhookEventType::ManualReview);

        return $saved;
    }

    /**
     * §SR-9 storage: extension-less random 20-byte-hex filename on the private
     * disk; MIME sniffed from CONTENT, never from the client-supplied type.
     *
     * @throws ApiException `invalid_upload` / `upload_too_large`.
     */
    private function storeReceipt(UploadedFile $receipt): string
    {
        if (! $receipt->isValid()) {
            throw ApiException::invalidUpload();
        }

        $maxBytes = (int) config('cardpay.uploads.max_mb', 5) * 1024 * 1024;
        if ($receipt->getSize() > $maxBytes) {
            throw ApiException::uploadTooLarge();
        }

        // finfo sniffs the actual bytes; the allow-list is image/PDF only.
        $allowed = (array) config('cardpay.uploads.allowed_mime', [
            'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
        ]);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($receipt->getRealPath()) ?: '';

        if (! in_array($mime, $allowed, true)) {
            throw ApiException::invalidUpload();
        }

        // Extension-less, unguessable filename on the private local disk.
        $name = bin2hex(random_bytes(20));
        $stored = $receipt->storeAs('receipts', $name, [
            'disk' => 'local',
            'visibility' => 'private',
        ]);

        if ($stored === false) {
            throw new ApiException(ApiErrorCode::UploadFailed);
        }

        return 'receipts/'.$name;
    }

    private function deleteReceipt(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        // Defence-in-depth: refuse anything that escapes the receipts directory.
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return;
        }

        Storage::disk('local')->delete($path);
    }
}
