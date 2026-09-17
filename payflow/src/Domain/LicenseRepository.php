<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * License 密钥（每笔订单每商品一枚）。
 */
final class LicenseRepository extends Repository
{
    protected static function collection(): string
    {
        return 'licenses';
    }

    protected static function idPrefix(): string
    {
        return 'lic_';
    }

    public function forOrder(string $orderId): ?array
    {
        foreach ($this->all() as $license) {
            if (($license['order_id'] ?? '') === $orderId) {
                return $license;
            }
        }

        return null;
    }

    public function findByKey(string $key): ?array
    {
        foreach ($this->all() as $license) {
            if (hash_equals((string) ($license['license_key'] ?? ''), $key)) {
                return $license;
            }
        }

        return null;
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 200): array
    {
        $list = array_values($this->all());
        usort($list, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($list, 0, $limit);
    }
}
