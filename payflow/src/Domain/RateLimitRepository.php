<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * API 限流计数桶（key:分钟）。
 */
final class RateLimitRepository extends Repository
{
    protected static function collection(): string
    {
        return 'rate_limits';
    }

    protected static function idPrefix(): string
    {
        return 'rlm_';
    }

    /**
     * 清理过期的分钟桶与日桶（cron）。
     */
    public function pruneStale(string $minuteBefore, string $dayBefore): int
    {
        $n = 0;
        foreach ($this->all() as $id => $row) {
            $kind = (string) ($row['kind'] ?? 'minute');
            $bucket = (string) ($row['bucket'] ?? '');
            $stale = $kind === 'day' ? $bucket < $dayBefore : $bucket < $minuteBefore;
            if ($stale) {
                $this->delete((string) $id);
                $n++;
            }
        }

        return $n;
    }

}
