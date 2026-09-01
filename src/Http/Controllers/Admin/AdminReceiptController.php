<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Admin;

use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Authorized receipt access for admins (§FR-12 / §SR-9).
 *
 * Receipts live on the PRIVATE local disk under extension-less random names,
 * outside the web root — the only way to fetch one is through this admin-gated
 * endpoint. Every download is audited (§SR-14: receipts are customer payment
 * evidence, in the same sensitivity class as credential reveals). The stored
 * path is validated against traversal before any file is touched.
 */
final class AdminReceiptController extends Controller
{
    /** MIME types we are willing to emit, mapped to safe download extensions. */
    private const MIME_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function download(Request $request, ManualReviewRequest $review): BinaryFileResponse
    {
        $path = $review->receipt_path;

        if ($path === null || $path === '') {
            throw new NotFoundHttpException('No receipt attached to this review.');
        }

        // Defence-in-depth: refuse anything escaping the receipts directory
        // (the writer already sanitizes, but never trust a stored value).
        if (str_contains($path, '..') || str_starts_with($path, '/') || ! str_starts_with($path, 'receipts/')) {
            throw new NotFoundHttpException('Invalid receipt path.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException('Receipt file is missing.');
        }

        $this->audit->log(
            action: 'receipt.downloaded',
            actorType: 'admin',
            actorId: $request->user()?->id,
            entityType: 'manual_review_request',
            entityId: (string) $review->id,
            new: ['payment_id' => $review->payment_id],
        );

        // Sniff the real content type so the browser gets correct rendering and
        // a sensible download filename — the stored name has no extension.
        $fullPath = $disk->path($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($fullPath) ?: 'application/octet-stream';
        $extension = self::MIME_EXTENSION[$mime] ?? null;

        if ($extension === null) {
            // Content no longer matches the allow-list (e.g. tampered upload) —
            // refuse rather than serve unknown bytes.
            throw new NotFoundHttpException('Receipt content type is not allowed.');
        }

        return response()->download($fullPath, "receipt-{$review->id}.{$extension}");
    }
}
