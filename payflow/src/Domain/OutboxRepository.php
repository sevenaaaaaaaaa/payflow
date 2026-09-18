<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 出站事件 Outbox：所有对外事件留痕，供下游增量拉取（GET /api/v1/events?since=）。
 */
final class OutboxRepository extends Repository
{
    protected static function collection(): string
    {
        return 'outbox';
    }

    protected static function idPrefix(): string
    {
        return 'obx_';
    }

    /**
     * 增量拉取：created_at 严格大于 since 的事件，按时间升序。
     *
     * @return list<array>
     */
    public function since(string $since, int $limit = 100): array
    {
        $rows = $this->query([], 0, 0, 'created_at', 'asc');
        $out = [];
        foreach ($rows as $row) {
            if ($since !== '' && (string) ($row['created_at'] ?? '') <= $since) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 100): array
    {
        return $this->query([], $limit, 0, 'created_at', 'desc');
    }
}
