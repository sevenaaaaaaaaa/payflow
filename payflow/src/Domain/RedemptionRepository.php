<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 优惠券核销记录（pending → used / released）。
 */
final class RedemptionRepository extends Repository
{
    protected static function collection(): string
    {
        return 'redemptions';
    }

    protected static function idPrefix(): string
    {
        return 'rdm_';
    }

    public function forOrder(string $orderId): ?array
    {
        return $this->firstBy('order_id', $orderId);
    }

    public function countForCustomer(string $couponId, string $email): int
    {
        $rows = $this->aggregate([
            ['field' => 'coupon_id', 'op' => '=', 'value' => $couponId],
            ['field' => 'email', 'op' => '=', 'value' => strtolower($email)],
            ['field' => 'status', 'op' => '!=', 'value' => 'released'],
        ]);

        return (int) ($rows[0]['count'] ?? 0);
    }
}
