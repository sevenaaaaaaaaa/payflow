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
        return $this->firstBy('order_id', $orderId);
    }

    public function findByKey(string $key): ?array
    {
        return $this->firstBy('license_key', $key);
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 200): array
    {
        return $this->query([], $limit, 0, 'created_at', 'desc');
    }
}
