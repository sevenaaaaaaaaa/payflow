<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 全局事件日志：订单/支付/退款/交付的审计轨迹。
 */
final class EventRepository extends Repository
{
    protected static function collection(): string
    {
        return 'events';
    }

    protected static function idPrefix(): string
    {
        return 'evt_';
    }

    public function log(string $type, array $payload = []): array
    {
        return $this->insert([
            'type' => $type,
            'payload' => $payload,
        ]);
    }

    /**
     * 保留策略：删除超过 days 天的旧事件；可选限制总行数（保留最新 maxRows 条）。
     *
     * @return int 删除条数
     */
    public function prune(int $days, int $maxRows = 0): int
    {
        $cutoff = time() - max(1, $days) * 86400;
        $removed = 0;
        foreach ($this->all() as $id => $event) {
            if ((strtotime((string) ($event['created_at'] ?? '')) ?: 0) < $cutoff) {
                $this->delete((string) $id);
                $removed++;
            }
        }
        if ($maxRows > 0) {
            $list = array_values($this->all());
            usort($list, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
            foreach (array_slice($list, $maxRows) as $old) {
                $this->delete((string) $old['id']);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 100): array
    {
        $list = array_values($this->all());
        usort($list, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($list, 0, $limit);
    }
}
