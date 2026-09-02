<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Http\Controllers\AdminApi;

use CartBecart\CardPay\Http\Controllers\AdminApi\Concerns\RespondsWithJson;
use CartBecart\CardPay\Http\Controllers\Controller;
use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\ApplicationApiKey;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use CartBecart\CardPay\Services\Provisioning\GatewayProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The gateway as a SINGLE resource (§16 lite).
 *
 * Full manages many merchant applications as a collection; a single-shop
 * install has exactly one and should never be asked to think about
 * `application_id`. So the same row is exposed here as one settings object:
 * webhook target, return-URL allow-list, token width, expiry, default card.
 *
 * This is not application CRUD — there is no create and no delete. It exists
 * because keeping webhooks and the HMAC API usable requires SOME way to set a
 * webhook URL and recover a lost secret, and a lite install has no panel to do
 * it in. Secrets are never readable: keys are listed by fingerprint metadata
 * only, and rotation reveals the new secret exactly once.
 */
final class GatewayController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly GatewayProvisioner $provisioner,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        return $this->ok($this->present($this->provisioner->resolve()));
    }

    public function update(Request $request): JsonResponse
    {
        $application = $this->provisioner->resolve();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'webhook_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'callback_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'allowed_domains' => ['sometimes', 'nullable'],
            'token_digits' => ['sometimes', 'integer', 'between:1,4'],
            'payment_expiration_minutes' => ['sometimes', 'integer', 'between:1,1440'],
            'default_bank_card_id' => ['sometimes', 'nullable', 'integer', 'exists:cp_bank_cards,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('allowed_domains', $data)) {
            $data['allowed_domains'] = $this->allowedDomains($data['allowed_domains']);
        }

        $before = [
            'webhook_url' => $application->webhook_url,
            'token_digits' => $application->token_digits,
            'payment_expiration_minutes' => $application->payment_expiration_minutes,
        ];

        $application->update($data);

        $this->audit->log(
            'gateway.updated', 'admin', $request->user()?->id, 'application', (string) $application->id,
            $before,
            [
                'webhook_url' => $application->webhook_url,
                'token_digits' => $application->token_digits,
                'payment_expiration_minutes' => $application->payment_expiration_minutes,
            ],
        );

        return $this->ok($this->present($application->refresh()));
    }

    /**
     * Retire every active key and mint a new pair. The secret in this response
     * is the only copy that will ever exist — it is not recoverable later.
     */
    public function rotateApiKey(Request $request): JsonResponse
    {
        $application = $this->provisioner->resolve();

        $credentials = $this->provisioner->rotateApiKey($application);

        // Fingerprint only: never the secret itself (§SR-14).
        $this->audit->log('gateway.api_key_rotated', 'admin', $request->user()?->id,
            'application', (string) $application->id, null, ['public_key' => $credentials->publicKey]);

        return $this->ok([
            'gateway' => $this->present($application->refresh()),
            'credentials' => $credentials->toArray(),
            'warning' => 'Store this secret now. It cannot be retrieved again.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Application $application): array
    {
        return [
            'id' => $application->id,
            'name' => $application->name,
            'slug' => $application->slug,
            'description' => $application->description,
            'public_key' => $application->public_key,
            'webhook_url' => $application->webhook_url,
            'callback_url' => $application->callback_url,
            'allowed_domains' => $application->allowedDomainList(),
            'is_active' => $application->is_active,
            'token_digits' => $application->token_digits,
            'payment_expiration_minutes' => $application->payment_expiration_minutes,
            'default_bank_card_id' => $application->default_bank_card_id,
            'api_keys' => $application->apiKeys()
                ->orderByDesc('id')
                ->get()
                ->map(fn (ApplicationApiKey $key): array => [
                    'id' => $key->id,
                    'public_key' => $key->public_key,
                    'label' => $key->label,
                    'is_active' => $key->is_active,
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                    'revoked_at' => $key->revoked_at?->toIso8601String(),
                    'created_at' => $key->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Accept a JSON array (natural for an API client) or the panel's
     * newline/comma separated text; store the canonical newline form.
     */
    private function allowedDomains(mixed $value): ?string
    {
        $items = is_array($value)
            ? $value
            : (preg_split('/[\r\n,]+/', (string) $value) ?: []);

        $hosts = [];
        foreach ($items as $item) {
            $host = strtolower(trim((string) $item));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts === [] ? null : implode("\n", array_unique($hosts));
    }
}
