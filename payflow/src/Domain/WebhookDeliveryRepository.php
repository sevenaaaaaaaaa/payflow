<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * Webhook 投递记录（含重试）。
 */
final class WebhookDeliveryRepository extends Repository
{
    protected static function collection(): string
    {
        return 'webhook_deliveries';
    }

    protected static function idPrefix(): string
    {
        return 'whd_';
    }

    /**
     * @return list<array>
     */
    public function dueForRetry(): array
    {
        $now = time();
        return array_values(array_filter($this->all(), static function (array $d) use ($now): bool {
            return ($d['status'] ?? '') === 'pending' && (strtotime((string) ($d['next_attempt_at'] ?? 'now')) ?: 0) <= $now;
        }));
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
