<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\RateLimitRepository;
use PayFlow\Support\Arr;

/**
 * 每 API Key 的分钟级 + 日配额限流（固定窗口）。
 */
final class RateLimiter
{
    public function __construct(
        private readonly RateLimitRepository $buckets,
        private readonly array $config = [],
    ) {
    }

    public function enabled(): bool
    {
        return (bool) Arr::get($this->config, 'api.rate_limit.enabled', true);
    }

    public function perMinute(): int
    {
        return max(1, (int) Arr::get($this->config, 'api.rate_limit.per_minute', 120));
    }

    public function perDay(): int
    {
        return max(1, (int) Arr::get($this->config, 'api.rate_limit.per_day', 10000));
    }

    /**
     * @return array{allowed:bool,remaining:int,reset:int,limit:int,daily_remaining:int,daily_reset:int,daily_limit:int}
     */
    public function check(string $keyId): array
    {
        $limit = $this->perMinute();
        $dayLimit = $this->perDay();
        $reset = (int) (strtotime(gmdate('Y-m-d H:i:00', time() + 60)) ?: time() + 60);
        $dayReset = (int) (strtotime(gmdate('Y-m-d 00:00:00', time() + 86400)) ?: time() + 86400);
        if (!$this->enabled()) {
            return [
                'allowed' => true,
                'remaining' => $limit,
                'reset' => $reset,
                'limit' => $limit,
                'daily_remaining' => $dayLimit,
                'daily_reset' => $dayReset,
                'daily_limit' => $dayLimit,
            ];
        }

        $minute = $this->bump($keyId, gmdate('YmdHi'), 'minute');
        $day = $this->bump($keyId, gmdate('Ymd'), 'day');

        return [
            'allowed' => $minute <= $limit && $day <= $dayLimit,
            'remaining' => max(0, $limit - $minute),
            'reset' => $reset,
            'limit' => $limit,
            'daily_remaining' => max(0, $dayLimit - $day),
            'daily_reset' => $dayReset,
            'daily_limit' => $dayLimit,
        ];
    }

    public function prune(int $keepMinutes = 5, int $keepDays = 14): int
    {
        $minuteBefore = gmdate('YmdHi', time() - $keepMinutes * 60);
        $dayBefore = gmdate('Ymd', time() - $keepDays * 86400);

        return $this->buckets->pruneStale($minuteBefore, $dayBefore);
    }

    private function bump(string $keyId, string $bucket, string $kind): int
    {
        $id = $kind === 'day' ? $keyId . ':d:' . $bucket : $keyId . ':' . $bucket;
        $existing = $this->buckets->find($id);
        $count = ((int) ($existing['count'] ?? 0)) + 1;
        if ($existing === null) {
            $this->buckets->insert([
                'id' => $id,
                'key_id' => $keyId,
                'bucket' => $bucket,
                'kind' => $kind,
                'count' => $count,
            ]);
        } else {
            $this->buckets->update((string) $existing['id'], ['count' => $count]);
        }

        return $count;
    }
}
