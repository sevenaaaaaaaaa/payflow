<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\RateLimitRepository;
use PayFlow\Support\Arr;

/**
 * 每 API Key 的分钟级限流（固定窗口）。
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

    /**
     * @return array{allowed:bool,remaining:int,reset:int,limit:int}
     */
    public function check(string $keyId): array
    {
        $limit = $this->perMinute();
        if (!$this->enabled()) {
            return ['allowed' => true, 'remaining' => $limit, 'reset' => time() + 60, 'limit' => $limit];
        }

        $bucket = gmdate('YmdHi');
        $reset = (int) (strtotime(gmdate('Y-m-d H:i:00', time() + 60)) ?: time() + 60);
        $existing = $this->buckets->find($keyId . ':' . $bucket);
        $count = ((int) ($existing['count'] ?? 0)) + 1;
        if ($existing === null) {
            $this->buckets->insert(['id' => $keyId . ':' . $bucket, 'key_id' => $keyId, 'bucket' => $bucket, 'count' => $count]);
        } else {
            $this->buckets->update((string) $existing['id'], ['count' => $count]);
        }

        return [
            'allowed' => $count <= $limit,
            'remaining' => max(0, $limit - $count),
            'reset' => $reset,
            'limit' => $limit,
        ];
    }

    public function prune(int $keepMinutes = 5): int
    {
        $before = gmdate('YmdHi', time() - $keepMinutes * 60);

        return $this->buckets->pruneBefore($before);
    }
}
