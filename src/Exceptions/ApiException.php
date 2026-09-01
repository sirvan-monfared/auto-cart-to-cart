<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Exceptions;

use CartBecart\CardPay\Enums\ApiErrorCode;
use RuntimeException;
use Throwable;

/**
 * A client-facing API failure carrying a §11.4 catalog code.
 *
 * The exception handler renders it as the canonical error envelope
 * `{"success":false,"error":{"code","message","details"}}` at the code's HTTP
 * status. Because these represent expected client errors (bad auth, validation,
 * conflicts), they are NOT reported to the log (see bootstrap/app.php); only
 * genuine faults surface as `internal_error` with details logged (§SR-15).
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  Extra machine-readable context
     *                                         (e.g. offending fields, retry_after).
     */
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        string $message = '',
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->message(), 0, $previous);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    /**
     * The wire envelope. `details` is always a JSON object (`{}` when empty) so
     * the shape is stable for clients.
     *
     * @return array{success: false, error: array{code: string, message: string, details: object}}
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $this->errorCode->value,
                'message' => $this->getMessage(),
                'details' => (object) $this->details,
            ],
        ];
    }

    // --- Named constructors for the codes thrown across the API ---------------

    public static function invalidApiKey(): self
    {
        return new self(ApiErrorCode::InvalidApiKey);
    }

    public static function invalidSignature(): self
    {
        return new self(ApiErrorCode::InvalidSignature);
    }

    public static function invalidDeviceKey(): self
    {
        return new self(ApiErrorCode::InvalidDeviceKey);
    }

    public static function invalidDeviceSignature(): self
    {
        return new self(ApiErrorCode::InvalidDeviceSignature);
    }

    /**
     * @param  array<string, string>  $fields  field name → reason
     */
    public static function validation(array $fields, string $message = ''): self
    {
        return new self(ApiErrorCode::ValidationFailed, $message, ['fields' => $fields]);
    }

    public static function invalidAmount(): self
    {
        return new self(ApiErrorCode::InvalidAmount);
    }

    public static function invalidBankCard(): self
    {
        return new self(ApiErrorCode::InvalidBankCard);
    }

    public static function tokenPoolExhausted(): self
    {
        return new self(ApiErrorCode::TokenPoolExhausted);
    }

    public static function idempotencyConflict(): self
    {
        return new self(ApiErrorCode::IdempotencyConflict);
    }

    public static function paymentNotFound(): self
    {
        return new self(ApiErrorCode::PaymentNotFound);
    }

    public static function paymentCannotBeCanceled(): self
    {
        return new self(ApiErrorCode::PaymentCannotBeCanceled);
    }

    public static function paymentNotReviewable(): self
    {
        return new self(ApiErrorCode::PaymentNotReviewable);
    }

    public static function invalidUpload(): self
    {
        return new self(ApiErrorCode::InvalidUpload);
    }

    public static function uploadTooLarge(): self
    {
        return new self(ApiErrorCode::UploadTooLarge);
    }

    public static function uploadFailed(): self
    {
        return new self(ApiErrorCode::UploadFailed);
    }

    public static function reviewNotFound(): self
    {
        return new self(ApiErrorCode::ReviewNotFound);
    }

    public static function invalidSms(): self
    {
        return new self(ApiErrorCode::InvalidSms);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(
            ApiErrorCode::RateLimitExceeded,
            details: ['retry_after' => max(0, $retryAfter)],
        );
    }
}
