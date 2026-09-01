<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Idempotency;

use CartBecart\CardPay\Models\IdempotencyKey;
use DateTimeInterface;

/**
 * The create-replay ledger for idempotent payment creation (§A8).
 *
 * This owns only the ledger primitives — hashing the request body, looking up /
 * inserting / completing a key row, and computing the TTL. The create
 * transaction and the replay-vs-conflict decision live in the PaymentService,
 * which composes these under a single DB::transaction so the key row, the
 * payment, and their link commit atomically.
 *
 * The request hash is a sha256 over a STABLE JSON encoding of the body: object
 * keys are recursively sorted while list order is preserved, so two requests
 * that are semantically identical but differ only in key order hash the same
 * (§A8: `request_hash = sha256(stable_json(body))`).
 */
final class IdempotencyStore
{
    /**
     * sha256 of the stable JSON encoding of the request body.
     *
     * @param  array<array-key, mixed>  $body
     */
    public function hashRequest(array $body): string
    {
        return hash('sha256', $this->stableJson($body));
    }

    /**
     * The existing ledger row for this application + key, or null.
     */
    public function find(int $applicationId, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('application_id', $applicationId)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Whether a stored row was created by a request with this exact body hash.
     */
    public function matches(IdempotencyKey $row, string $requestHash): bool
    {
        return $row->request_hash === $requestHash;
    }

    /**
     * Claim the key by inserting a pending row (no payment linked yet). Throws
     * Illuminate\Database\QueryException on the unique (application_id,
     * idempotency_key) index when a concurrent request already claimed it — the
     * caller re-selects the winner and replays.
     */
    public function begin(int $applicationId, string $key, string $requestHash): IdempotencyKey
    {
        return IdempotencyKey::query()->create([
            'application_id' => $applicationId,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'payment_id' => null,
            'response_json' => null,
            'expires_at' => $this->ttlExpiry(),
        ]);
    }

    /**
     * Link the created payment and freeze its response for byte-identical
     * replays. Runs inside the create transaction so it commits with the row.
     *
     * @param  array<string, mixed>  $response  the create-time response `data`
     */
    public function complete(IdempotencyKey $row, int $paymentId, array $response): void
    {
        $row->update([
            'payment_id' => $paymentId,
            'response_json' => $response,
        ]);
    }

    /**
     * When a claimed key expires (§A8: 24 h, then purgeable).
     */
    public function ttlExpiry(): DateTimeInterface
    {
        return now()->addHours((int) config('cardpay.idempotency.ttl_hours', 24));
    }

    /**
     * Deterministic JSON: recursively sort object keys, preserve list order.
     */
    private function stableJson(mixed $value): string
    {
        return (string) json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Recursively order associative keys; leave lists (and scalars) as-is so JSON
     * array semantics are preserved.
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
