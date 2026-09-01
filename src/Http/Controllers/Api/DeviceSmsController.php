<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\Api;

use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Http\Middleware\DeviceHmacAuth;
use CartBecart\CardPay\Models\Device;
use CartBecart\CardPay\Services\Sms\SmsIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Device SMS relay endpoints (§11.2 #8–9), behind `device.hmac` (incoming-sms)
 * and `device.shortcut` (shortcut-sms).
 *
 * Both share one ingestion pipeline; they differ only in authentication and in
 * the shortcut surface's convenience defaults (empty message_id/sender/
 * received_at get derived values, per §FR-9). Validation happens HERE; the
 * service owns dedupe, persistence, parsing, and matching.
 */
final class DeviceSmsController extends Controller
{
    public function __construct(private readonly SmsIngestionService $ingestion) {}

    /** POST /api/v1/devices/incoming-sms — full HMAC device auth. */
    public function incomingSms(Request $request): JsonResponse
    {
        return $this->relay($request, shortcutDefaults: false);
    }

    /** POST /api/v1/devices/shortcut-sms — static key+secret auth, lenient fields. */
    public function shortcutSms(Request $request): JsonResponse
    {
        return $this->relay($request, shortcutDefaults: true);
    }

    private function relay(Request $request, bool $shortcutDefaults): JsonResponse
    {
        $device = $this->device($request);
        $input = $this->validated($request, $shortcutDefaults);

        $outcome = $this->ingestion->ingest(
            device: $device,
            messageId: $input['message_id'],
            rawSms: $input['raw_sms'],
            receivedAt: $input['received_at'],
            sender: $input['sender'],
            sourceIp: (string) ($request->ip() ?? ''),
        );

        return response()->json(['success' => true, 'data' => $outcome->toArray()], $outcome->status());
    }

    /**
     * The device authenticated by the route's gate. Its absence would mean the
     * gate was bypassed; fail closed.
     */
    private function device(Request $request): Device
    {
        $device = $request->attributes->get(DeviceHmacAuth::DEVICE_ATTRIBUTE);

        if (! $device instanceof Device) {
            throw ApiException::invalidDeviceKey();
        }

        return $device;
    }

    /**
     * §FR-9 body validation. `message_id` ≤ 190 (column width), `raw_sms`
     * ≤ 10 000, `received_at` ISO-8601 normalized to UTC. In shortcut mode an
     * empty message_id derives from the body hash, empty sender defaults to
     * "iOS Shortcut", and a missing/empty received_at means now.
     *
     * @return array{message_id: string, raw_sms: string, received_at: \DateTimeInterface, sender: string|null}
     */
    private function validated(Request $request, bool $shortcutDefaults): array
    {
        $fields = [];

        $messageId = trim((string) $request->input('message_id', ''));
        if ($shortcutDefaults && $messageId === '') {
            // Same content always yields the same derived id, so a Shortcut that
            // retries itself is still deduped on UNIQUE(device_id, message_id).
            $messageId = 'ios_'.hash('sha256', (string) $request->input('raw_sms', ''));
        }
        if ($messageId === '' || mb_strlen($messageId) > 190) {
            $fields['message_id'] = 'required, at most 190 characters.';
        }

        $rawSms = (string) $request->input('raw_sms', '');
        if ($rawSms === '' || mb_strlen($rawSms) > 10_000) {
            $fields['raw_sms'] = 'required, at most 10000 characters.';
        }

        $receivedRaw = trim((string) $request->input('received_at', ''));
        if ($shortcutDefaults && $receivedRaw === '') {
            $receivedAt = now();
        } else {
            try {
                $parsed = Carbon::parse($receivedRaw);
                if ($receivedRaw === '' || ! $parsed->isValid()) {
                    throw new \RuntimeException('empty or invalid');
                }
                $receivedAt = $parsed->utc();
            } catch (\Throwable) {
                $fields['received_at'] = 'must be a valid ISO-8601 timestamp.';
                // Placeholder consumed nowhere — a validation error throws below.
                $receivedAt = now();
            }
        }

        $sender = trim((string) $request->input('sender', ''));
        if ($sender === '' && $shortcutDefaults) {
            $sender = 'iOS Shortcut';
        }

        if ($fields !== []) {
            throw ApiException::validation($fields);
        }

        return [
            'message_id' => $messageId,
            'raw_sms' => $rawSms,
            'received_at' => $receivedAt,
            'sender' => $sender !== '' ? $sender : null,
        ];
    }
}
