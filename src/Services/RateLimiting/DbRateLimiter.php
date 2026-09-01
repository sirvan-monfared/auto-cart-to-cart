<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\RateLimiting;

use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Models\RateLimit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * DB-backed fixed-window rate limiter (§A7) — no Redis, no cron.
 *
 * Each window is `floor(now / W) * W`; the subject is hashed (sha256) so raw
 * identifiers (IPs, keys) never touch the table. Counting is race-safe WITHOUT
 * a read-then-write: we attempt an atomic `attempts = attempts + 1` UPDATE, and
 * only if no row exists yet do we INSERT — a lost INSERT race (unique violation
 * on `(scope, rate_key, window_start)`) falls back to the same atomic UPDATE.
 * Under contention the count can only over-report, so the limiter fails closed
 * (never lets a caller exceed the cap).
 *
 * Rows are purged by budgeted maintenance (§A9); they carry an expiry well past
 * the window so a late purge can never resurrect a stale count.
 */
final class DbRateLimiter
{
    /**
     * Register one hit against a limit, throwing 429 when the cap is exceeded.
     *
     * @param  string  $scope  Bucket family, e.g. 'api', 'device', 'login'.
     * @param  string  $subject  Raw subject (hashed before storage), e.g. "app:{id}".
     * @param  int  $limit  Max hits permitted within the window.
     * @param  int|null  $windowSeconds  Window width; defaults to the configured width.
     * @return int The current attempt count within the active window.
     *
     * @throws ApiException `rate_limit_exceeded` (429) with `details.retry_after` seconds.
     */
    public function hit(string $scope, string $subject, int $limit, ?int $windowSeconds = null): int
    {
        $window = $windowSeconds ?? (int) config('cardpay.rate_limits.window_seconds', 60);
        $window = max(1, $window);

        $ts = now()->getTimestamp();
        $windowStart = intdiv($ts, $window) * $window;
        $bucketEnd = $windowStart + $window;
        $keyHash = hash('sha256', $subject);

        // §A7: rows expire at bucket_end + window + 60 s, so purging is always safe.
        $expiresAt = Carbon::createFromTimestamp($bucketEnd + $window + 60);

        $attempts = $this->registerHit($scope, $keyHash, $windowStart, $expiresAt);

        if ($attempts > $limit) {
            throw ApiException::rateLimited($bucketEnd - $ts);
        }

        return $attempts;
    }

    /**
     * Atomically bump (or create) the counter for this bucket and return the
     * resulting attempt count.
     */
    private function registerHit(string $scope, string $keyHash, int $windowStart, Carbon $expiresAt): int
    {
        if ($this->bump($scope, $keyHash, $windowStart) > 0) {
            return $this->current($scope, $keyHash, $windowStart);
        }

        try {
            RateLimit::query()->create([
                'scope' => $scope,
                'rate_key' => $keyHash,
                'window_start' => $windowStart,
                'attempts' => 1,
                'expires_at' => $expiresAt,
            ]);

            return 1;
        } catch (QueryException $e) {
            // A concurrent request created the bucket between our UPDATE and
            // INSERT — bump the now-existing row instead of double counting.
            if ((string) $e->getCode() === '23000') {
                $this->bump($scope, $keyHash, $windowStart);

                return $this->current($scope, $keyHash, $windowStart);
            }

            throw $e;
        }
    }

    /**
     * Atomic `attempts = attempts + 1`; returns rows affected (0 when absent).
     */
    private function bump(string $scope, string $keyHash, int $windowStart): int
    {
        return $this->bucket($scope, $keyHash, $windowStart)->increment('attempts');
    }

    private function current(string $scope, string $keyHash, int $windowStart): int
    {
        return (int) $this->bucket($scope, $keyHash, $windowStart)->value('attempts');
    }

    /**
     * @return Builder<RateLimit>
     */
    private function bucket(string $scope, string $keyHash, int $windowStart): Builder
    {
        return RateLimit::query()
            ->where('scope', $scope)
            ->where('rate_key', $keyHash)
            ->where('window_start', $windowStart);
    }
}
