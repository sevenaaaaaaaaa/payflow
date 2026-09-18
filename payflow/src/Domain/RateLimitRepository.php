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
     * 清理早于 $before 的桶（cron）。
     */
    public function pruneBefore(string $before): int
    {
        $n = 0;
        foreach ($this->all() as $id => $row) {
            if ((string) ($row['bucket'] ?? '') < $before) {
                $this->delete((string) $id);
                $n++;
            }
        }

        return $n;
    }
}
