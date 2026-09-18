<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 登录失败计数（按 用户名|IP 哈希），用于限速与锁定。
 */
final class LoginAttemptRepository extends Repository
{
    protected static function collection(): string
    {
        return 'login_attempts';
    }

    protected static function idPrefix(): string
    {
        return 'lat_';
    }

    public function key(string $username, string $ip): string
    {
        return hash('sha256', strtolower(trim($username)) . '|' . $ip);
    }

    public function find2(string $username, string $ip): ?array
    {
        return $this->find($this->key($username, $ip));
    }

    /**
     * 清理早于 N 天未更新的记录（cron）。
     */
    public function pruneOlderThan(int $days): int
    {
        $cutoff = time() - max(1, $days) * 86400;
        $n = 0;
        foreach ($this->all() as $id => $row) {
            if ((strtotime((string) ($row['updated_at'] ?? $row['created_at'] ?? '')) ?: 0) < $cutoff) {
                $this->delete((string) $id);
                $n++;
            }
        }

        return $n;
    }
}
