<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Webhooks;

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Models\WebhookDelivery;
use CartBecart\CardPay\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HTTP webhook delivery with a fail-safe retry ladder (§A6 / §FR-13).
 *
 * Each pass:
 *   1. ensure every undelivered event has exactly ONE delivery row (URL
 *      resolved webhook_url ∥ callback_url, http(s) only) — created here
 *      because emission must stay free of network decisions;
 *   2. attempt up to $limit DUE deliveries ordered by next_attempt_at;
 *   3. POST the exact stored payload JSON, signed with the application's
 *      latest active non-revoked API-key secret; success ⇔ HTTP 2xx;
 *   4. on failure schedule the next attempt via RETRY_MINUTES[attempt] =
 *      [0,1,5,15,60]; after the last, status becomes exhausted.
 *
 * Hard rules: no outbound call ever throws into the caller (a webhook failure
 * NEVER alters financial state); response bodies are truncated to 4000 chars,
 * error strings to 500; timeouts are connect 3 s / total 8 s.
 */
final class HttpWebhookProcessor implements WebhookProcessor
{
    /** Delay in minutes before retry N (index = attempt number just failed). */
    private const RETRY_MINUTES = [0, 1, 5, 15, 60];

    public function processDue(int $limit): int
    {
        $this->createMissingDeliveries();

        $due = WebhookDelivery::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(max(1, $limit))
            ->get();

        foreach ($due as $delivery) {
            $this->deliver($delivery);
        }

        return $due->count();
    }

    /**
     * Admin-triggered manual retry (§FR-13): re-arm a failed/exhausted delivery
     * for immediate attempt on the next maintenance pass. Attempt history is
     * preserved; the ladder restarts from where it left.
     */
    public function retry(int $deliveryId): bool
    {
        $delivery = WebhookDelivery::query()->find($deliveryId);

        if (! $delivery instanceof WebhookDelivery
            || ! in_array($delivery->status->value, ['failed', 'exhausted'], true)) {
            return false;
        }

        $delivery->forceFill([
            'status' => 'pending',
            'next_attempt_at' => now(),
        ])->save();

        return true;
    }

    /**
     * §FR-13: one delivery record per event. Events without one get their URL
     * resolved now; an application with no usable http(s) endpoint gets a
     * permanently-exhausted delivery so it never blocks the queue.
     */
    private function createMissingDeliveries(): void
    {
        WebhookEvent::query()
            ->whereDoesntHave('deliveries')
            ->with('application')
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    /** @var Application|null $application */
                    $application = $event->application;
                    $url = $this->resolveUrl($application);

                    WebhookDelivery::query()->create([
                        'webhook_event_id' => $event->id,
                        'url' => $url ?? '',
                        'attempt' => 0,
                        // No usable endpoint: terminal, not retryable noise.
                        'status' => $url === null ? 'exhausted' : 'pending',
                        'error_message' => $url === null ? 'no_webhook_url_configured' : null,
                        'next_attempt_at' => now(),
                    ]);
                }
            });
    }

    private function resolveUrl(?Application $application): ?string
    {
        if (! $application instanceof Application) {
            return null;
        }

        foreach ([$application->webhook_url, $application->callback_url] as $candidate) {
            if (is_string($candidate) && preg_match('#^https?://#i', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * One signed delivery attempt. Everything is caught: this runs inside
     * budgeted maintenance and must never disturb the triggering request.
     */
    private function deliver(WebhookDelivery $delivery): void
    {
        try {
            $this->attempt($delivery);
        } catch (Throwable) {
            // attempt() is itself defensive; this is a final backstop so no
            // fault can escape into maintenance or the user's request.
        }
    }

    private function attempt(WebhookDelivery $delivery): void
    {
        $event = $delivery->event;

        if (! $event instanceof WebhookEvent || $delivery->url === '') {
            $delivery->forceFill([
                'status' => 'exhausted',
                'error_message' => $delivery->error_message ?? 'no_webhook_url_configured',
            ])->save();

            return;
        }

        // §FR-13: the exact stored payload is what gets sent AND signed.
        $body = json_encode($event->payload_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $secret = $this->activeSecret($event->application_id);
        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('cardpay.webhooks.timeout', 8))
                ->connectTimeout((int) config('cardpay.webhooks.connect_timeout', 3))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => (string) config('cardpay.webhooks.user_agent', 'CardPay-Webhook/1.0'),
                    'X-CardPay-Signature' => hash_hmac('sha256', $body, $secret),
                ])
                ->withBody($body, 'application/json')
                ->post($delivery->url);

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $this->record($delivery, [
                'response_status' => $response->status(),
                'response_body' => mb_substr($response->body(), 0, (int) config('cardpay.webhooks.max_response_body', 4000)),
                'duration_ms' => $durationMs,
                'error_message' => null,
            ], $response->successful());
        } catch (Throwable $e) {
            // Connection failure / timeout / DNS: recorded, retried, never fatal.
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $this->record($delivery, [
                'response_status' => null,
                'response_body' => null,
                'duration_ms' => $durationMs,
                'error_message' => mb_substr($e->getMessage(), 0, (int) config('cardpay.webhooks.max_error', 500)),
            ], false);
        }
    }

    /**
     * The signing secret: latest ACTIVE, non-revoked key of the event's
     * application, decrypted transparently by the Encrypted cast (§SR-1).
     */
    private function activeSecret(int $applicationId): string
    {
        $key = ApplicationApiKey::query()
            ->where('application_id', $applicationId)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        return $key instanceof ApplicationApiKey ? (string) $key->secret_encrypted : '';
    }

    /**
     * Persist the attempt outcome and schedule the next try per the ladder.
     *
     * @param  array{response_status: int|null, response_body: string|null,
     *       duration_ms: int, error_message: string|null}  $outcome
     */
    private function record(WebhookDelivery $delivery, array $outcome, bool $success): void
    {
        $attempt = $delivery->attempt + 1;
        $attributes = [
            ...$outcome,
            'attempt' => $attempt,
            'last_attempt_at' => now(),
        ];

        if ($success) {
            // Terminal success: no further attempt is ever scheduled.
            $attributes['status'] = 'delivered';
            $attributes['next_attempt_at'] = null;
        } else {
            $ladder = self::RETRY_MINUTES;

            // Rung N-1 is the delay BEFORE retry N; once every rung has been
            // consumed (the count-th failure) no retry remains: exhausted.
            if ($attempt >= count($ladder)) {
                $attributes['status'] = 'exhausted';
            } else {
                $attributes['status'] = 'failed';
                $attributes['next_attempt_at'] = Carbon::parse(now())->addMinutes((int) $ladder[$attempt - 1]);
            }
        }

        $delivery->forceFill($attributes)->save();
    }
}
