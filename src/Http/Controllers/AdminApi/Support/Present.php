<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi\Support;

use CartBecart\CardPay\Models\BankCard;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Models\IncomingSms;
use CartBecart\CardPay\Models\ManualReviewRequest;
use CartBecart\CardPay\Models\Payment;
use CartBecart\CardPay\Models\SmsParser;
use CartBecart\CardPay\Models\WebhookDelivery;
use CartBecart\CardPay\Models\WebhookEvent;

/**
 * JSON shapes for the Admin API.
 *
 * One place decides what an admin surface exposes, which is what keeps the
 * secrets out: card PANs, device secrets, and API secrets are all decryptable
 * by their casts, so leaving serialization to the models would leak them the
 * moment someone added a `->toJson()`. Nothing here emits a secret except the
 * one-time reveal on issue/rotation, which is explicit and separate.
 *
 * Timestamps are ISO-8601 UTC, matching the merchant API; the client formats
 * for its own timezone.
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function payment(Payment $payment): array
    {
        return [
            'payment_id' => $payment->public_id,
            'status' => $payment->status->value,
            'original_amount' => $payment->original_amount,
            'token' => $payment->token,
            'payable_amount' => $payment->payable_amount,
            'currency' => $payment->currency,
            'external_order_id' => $payment->external_order_id,
            'description' => $payment->description,
            'customer' => [
                'name' => $payment->customer_name,
                'mobile' => $payment->customer_mobile,
                'reference' => $payment->customer_reference,
            ],
            'bank_card_id' => $payment->bank_card_id,
            'expires_at' => $payment->expires_at->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'canceled_at' => $payment->canceled_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    /**
     * A payment plus everything an admin needs to adjudicate it: the SMS that
     * settled it (or the candidates that did not), the review thread, and the
     * webhook delivery history.
     *
     * @param  iterable<int, IncomingSms>  $smsEvidence
     * @param  iterable<int, WebhookEvent>  $webhookEvents
     * @return array<string, mixed>
     */
    public static function paymentDetail(Payment $payment, iterable $smsEvidence, iterable $webhookEvents): array
    {
        $card = $payment->bankCard;

        return [
            ...self::payment($payment),
            'driver' => $payment->driver,
            'return_url' => $payment->return_url,
            'callback_url' => $payment->callback_url,
            'metadata' => $payment->metadata_json,
            'bank_card' => $card instanceof BankCard ? [
                'id' => $card->id,
                'title' => $card->title,
                'bank_name' => $card->bank_name,
                'last_four' => $card->card_number_last_four,
            ] : null,
            'sms_evidence' => array_map(self::sms(...), self::toList($smsEvidence)),
            'reviews' => array_map(self::review(...), self::toList($payment->reviews)),
            'webhook_events' => array_map(self::webhookEvent(...), self::toList($webhookEvents)),
        ];
    }

    /**
     * Card listings never carry the PAN. The full number is available only
     * from the single-card endpoint, which asks for it deliberately.
     *
     * @return array<string, mixed>
     */
    public static function card(BankCard $card, bool $withNumber = false): array
    {
        $data = [
            'id' => $card->id,
            'title' => $card->title,
            'bank_name' => $card->bank_name,
            'last_four' => $card->card_number_last_four,
            'card_holder_name' => $card->card_holder_name,
            'description' => $card->description,
            'sms_parser_id' => $card->sms_parser_id,
            'is_active' => $card->is_active,
            'created_at' => $card->created_at?->toIso8601String(),
        ];

        if ($withNumber) {
            // Decrypted in memory only (§SR-1). The same digits are shown to
            // every customer on the checkout page, so this is not a secret —
            // but it stays off list responses to keep the blast radius small.
            $data['card_number'] = (string) $card->card_number_encrypted;
            $data['iban'] = $card->iban_encrypted !== null ? (string) $card->iban_encrypted : null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function device(Device $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'platform' => $device->platform->value,
            'device_key' => $device->device_key,
            'bank_card_id' => $device->bank_card_id,
            'is_active' => $device->is_active,
            'is_usable' => $device->isUsable(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'last_ip' => $device->last_ip,
            'sms_count' => $device->sms_count,
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function parser(SmsParser $parser): array
    {
        return [
            'id' => $parser->id,
            'name' => $parser->name,
            'bank_name' => $parser->bank_name,
            'sender_pattern' => $parser->sender_pattern,
            'amount_regex' => $parser->amount_regex,
            'date_regex' => $parser->date_regex,
            'time_regex' => $parser->time_regex,
            'transaction_type_regex' => $parser->transaction_type_regex,
            'positive_keywords' => $parser->positive_keywords,
            'negative_keywords' => $parser->negative_keywords,
            'sample_sms' => $parser->sample_sms,
            'is_active' => $parser->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function sms(IncomingSms $sms): array
    {
        return [
            'id' => $sms->id,
            'device_id' => $sms->device_id,
            'bank_card_id' => $sms->bank_card_id,
            'sender' => $sms->sender,
            'raw_sms' => $sms->raw_sms,
            'received_at' => $sms->received_at->toIso8601String(),
            'server_received_at' => $sms->server_received_at->toIso8601String(),
            'parse_status' => $sms->parse_status->value,
            'parsed_amount' => $sms->parsed_amount,
            'parsed_transaction_at' => $sms->parsed_transaction_at?->toIso8601String(),
            'parse_error' => $sms->parse_error,
            'match_status' => $sms->match_status->value,
            'matched_payment_id' => $sms->matched_payment_id,
            'used_at' => $sms->used_at?->toIso8601String(),
            'created_at' => $sms->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function review(ManualReviewRequest $review): array
    {
        $payment = $review->relationLoaded('payment') ? $review->payment : null;

        return [
            'id' => $review->id,
            'payment_id' => $review->payment_id,
            'payment' => $payment instanceof Payment ? [
                'payment_id' => $payment->public_id,
                'status' => $payment->status->value,
                'payable_amount' => $payment->payable_amount,
                'currency' => $payment->currency,
                'bank_card_id' => $payment->bank_card_id,
            ] : null,
            'incoming_sms_id' => $review->incoming_sms_id,
            'reported_amount' => $review->reported_amount,
            'approximate_paid_at' => $review->approximate_paid_at?->toIso8601String(),
            'contact_mobile' => $review->contact_mobile,
            'customer_note' => $review->customer_note,
            'has_receipt' => $review->receipt_path !== null && $review->receipt_path !== '',
            'actual_amount' => $review->actual_amount,
            'internal_note' => $review->internal_note,
            'status' => $review->status,
            'reviewed_by' => $review->reviewed_by,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function webhookEvent(WebhookEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_id' => $event->event_id,
            'payment_id' => $event->payment_id,
            'event_type' => $event->event_type->value,
            'payload' => $event->payload_json,
            'created_at' => $event->created_at?->toIso8601String(),
            'deliveries' => array_map(self::delivery(...), self::toList($event->deliveries)),
        ];
    }

    /** @return array<string, mixed> */
    public static function delivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'url' => $delivery->url,
            'attempt' => $delivery->attempt,
            'status' => $delivery->status->value,
            'response_status' => $delivery->response_status,
            'duration_ms' => $delivery->duration_ms,
            'error_message' => $delivery->error_message,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
        ];
    }

    /**
     * @template T
     *
     * @param  iterable<int, T>|null  $items
     * @return list<T>
     */
    private static function toList(?iterable $items): array
    {
        if ($items === null) {
            return [];
        }

        return is_array($items) ? array_values($items) : iterator_to_array($items, false);
    }
}
