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
     * @return list<array>
     */
    public function recent(int $limit = 100): array
    {
        $list = array_values($this->all());
        usort($list, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($list, 0, $limit);
    }
}
