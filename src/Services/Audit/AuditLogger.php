<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Audit;

use CartBecart\CardPay\Models\AuditLog;
use CartBecart\CardPay\Support\Edition;
use Illuminate\Http\Request;
use Throwable;

/**
 * Tamper-evident audit trail writer (§SR-14).
 *
 * Records security-relevant actions — logins, approvals/rejections, key and
 * secret rotations, credential reveals, settings changes — with actor, entity,
 * before/after values, IP, and user agent.
 *
 * Hard rules:
 *   • NEVER log secrets in plaintext: callers pass redacted diffs (the
 *     fingerprint or last-four, not the value);
 *   • audit writing must never break the business operation it witnesses —
 *     a logging failure is swallowed after being reported to the log channel;
 *   • request context (IP/UA) is read opportunistically; CLI runs simply omit it.
 *
 * When the `audit` feature is off (the lite default) there is no cp_audit_logs
 * table and every call is a no-op — the host application owns the activity
 * log. Callers stay unconditional so no business code has to branch (§16).
 */
final class AuditLogger
{
    /**
     * @param  string  $actorType  e.g. 'admin', 'system', 'device'
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $action,
        string $actorType,
        ?int $actorId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $old = null,
        ?array $new = null,
    ): void {
        if (! Edition::enabled('audit')) {
            return;
        }

        try {
            AuditLog::query()->create([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $old,
                'new_values' => $new,
                'ip' => $this->currentIp(),
                'user_agent' => $this->currentUserAgent(),
            ]);
        } catch (Throwable $e) {
            // The witnessed operation must proceed even if the audit write fails.
            report($e);
        }
    }

    private function currentIp(): ?string
    {
        $request = $this->currentRequest();

        return $request !== null ? substr((string) $request->ip(), 0, 64) : null;
    }

    private function currentUserAgent(): ?string
    {
        $request = $this->currentRequest();
        if ($request === null) {
            return null;
        }

        return mb_substr((string) $request->userAgent(), 0, 500) ?: null;
    }

    /**
     * The live request when called inside an HTTP lifecycle, else null (CLI).
     */
    private function currentRequest(): ?Request
    {
        try {
            /** @var Request|null $request */
            $request = app('request');
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }
}
