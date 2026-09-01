<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Enums;

/**
 * The authoritative merchant/device API error catalog (§11.4).
 *
 * Each case is the stable `code` returned in the error envelope
 * `{"success":false,"error":{"code","message","details"}}`; the HTTP status and
 * a default human message are derived here so every throw site maps to exactly
 * one catalog entry and can never drift from the spec.
 */
enum ApiErrorCode: string
{
    case InvalidApiKey = 'invalid_api_key';
    case InvalidSignature = 'invalid_signature';
    case InvalidDeviceKey = 'invalid_device_key';
    case InvalidDeviceSignature = 'invalid_device_signature';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case ValidationFailed = 'validation_failed';
    case InvalidAmount = 'invalid_amount';
    case InvalidBankCard = 'invalid_bank_card';
    case TokenPoolExhausted = 'token_pool_exhausted';
    case IdempotencyConflict = 'idempotency_conflict';
    case PaymentNotFound = 'payment_not_found';
    case PaymentCannotBeCanceled = 'payment_cannot_be_canceled';
    case InvalidStatusTransition = 'invalid_status_transition';
    case PaymentNotReviewable = 'payment_not_reviewable';
    case ReviewNotFound = 'review_not_found';
    case InvalidSms = 'invalid_sms';
    case CsrfMismatch = 'csrf_mismatch';
    case UploadFailed = 'upload_failed';
    case UploadTooLarge = 'upload_too_large';
    case InvalidUpload = 'invalid_upload';
    case InternalError = 'internal_error';

    /**
     * HTTP status for this code (§11.4). Kept in one place so the wire contract
     * is single-sourced.
     */
    public function status(): int
    {
        return match ($this) {
            self::InvalidApiKey,
            self::InvalidSignature,
            self::InvalidDeviceKey,
            self::InvalidDeviceSignature => 401,

            self::RateLimitExceeded => 429,

            self::ValidationFailed,
            self::InvalidAmount,
            self::InvalidBankCard,
            self::InvalidSms,
            self::UploadFailed,
            self::UploadTooLarge,
            self::InvalidUpload => 422,

            self::TokenPoolExhausted,
            self::IdempotencyConflict,
            self::PaymentCannotBeCanceled,
            self::InvalidStatusTransition,
            self::PaymentNotReviewable => 409,

            self::PaymentNotFound,
            self::ReviewNotFound => 404,

            self::CsrfMismatch => 419,

            self::InternalError => 500,
        };
    }

    /**
     * A safe, generic default message. Never leaks internal detail (§SR-15);
     * specific context, when useful, travels in the envelope's `details`.
     */
    public function message(): string
    {
        return match ($this) {
            self::InvalidApiKey => 'Unknown or inactive application key, or missing authentication headers.',
            self::InvalidSignature => 'Invalid signature, stale timestamp, or reused nonce.',
            self::InvalidDeviceKey => 'Unknown device or missing device credentials.',
            self::InvalidDeviceSignature => 'Device authentication failed.',
            self::RateLimitExceeded => 'Too many requests. Please retry later.',
            self::ValidationFailed => 'The request failed validation.',
            self::InvalidAmount => 'Amount must be a positive integer in minor units.',
            self::InvalidBankCard => 'The selected bank card is missing or inactive.',
            self::TokenPoolExhausted => 'No payment token is currently available for this amount and card.',
            self::IdempotencyConflict => 'This idempotency key was already used with a different request body.',
            self::PaymentNotFound => 'Payment not found.',
            self::PaymentCannotBeCanceled => 'This payment can no longer be canceled.',
            self::InvalidStatusTransition => 'The requested status change is not allowed.',
            self::PaymentNotReviewable => 'This payment can no longer be submitted for review.',
            self::ReviewNotFound => 'Review not found.',
            self::InvalidSms => 'The linked SMS belongs to a different card.',
            self::CsrfMismatch => 'Missing or expired CSRF token.',
            self::UploadFailed => 'The receipt could not be stored.',
            self::UploadTooLarge => 'The receipt exceeds the maximum allowed size.',
            self::InvalidUpload => 'The receipt file type is not allowed.',
            self::InternalError => 'An unexpected error occurred.',
        };
    }
}
