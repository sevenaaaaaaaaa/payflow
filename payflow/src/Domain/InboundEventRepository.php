<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 入站事件（其它矩阵产品 → PayFlow），按 idempotency_key 去重。
 */
final class InboundEventRepository extends Repository
{
    protected static function collection(): string
    {
        return 'inbound_events';
    }

    protected static function idPrefix(): string
    {
        return 'ine_';
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        return $this->firstBy('idempotency_key', $key);
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 100): array
    {
        return $this->query([], $limit, 0, 'created_at', 'desc');
    }
}
