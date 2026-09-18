<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\LoginAttemptRepository;
use PayFlow\Support\Arr;

/**
 * 后台登录限速：连续失败达上限后锁定一段时间。
 */
final class LoginThrottle
{
    public function __construct(
        private readonly LoginAttemptRepository $attempts,
        private readonly array $config = [],
    ) {
    }

    private function maxAttempts(): int
    {
        return max(1, (int) Arr::get($this->config, 'admin.login_max_attempts', 5));
    }

    private function lockMinutes(): int
    {
        return max(1, (int) Arr::get($this->config, 'admin.login_lock_minutes', 15));
    }

    public function lockedSeconds(string $username, string $ip): int
    {
        $row = $this->attempts->find2($username, $ip);
        if ($row === null) {
            return 0;
        }
        $until = (int) ($row['locked_until'] ?? 0);

        return $until > time() ? $until - time() : 0;
    }

    /**
     * @return array{locked:bool,remaining:int,count:int}
     */
    public function failure(string $username, string $ip): array
    {
        $id = $this->attempts->key($username, $ip);
        $row = $this->attempts->find($id);
        $count = ((int) ($row['count'] ?? 0)) + 1;
        $locked = $count >= $this->maxAttempts();
        $payload = [
            'id' => $id,
            'username' => strtolower(trim($username)),
            'ip' => $ip,
            'count' => $locked ? 0 : $count,
            'locked_until' => $locked ? time() + $this->lockMinutes() * 60 : (int) ($row['locked_until'] ?? 0),
            'updated_at' => date('c'),
        ];
        if ($row === null) {
            $this->attempts->insert($payload);
        } else {
            $this->attempts->update($id, $payload);
        }

        return ['locked' => $locked, 'remaining' => $locked ? $this->lockMinutes() * 60 : 0, 'count' => $count];
    }

    public function clear(string $username, string $ip): void
    {
        $id = $this->attempts->key($username, $ip);
        if ($this->attempts->find($id) !== null) {
            $this->attempts->delete($id);
        }
    }

    public function prune(int $days = 7): int
    {
        return $this->attempts->pruneOlderThan($days);
    }
}
