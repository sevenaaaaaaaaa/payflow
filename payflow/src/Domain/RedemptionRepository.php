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
        foreach ($this->all() as $redemption) {
            if (($redemption['order_id'] ?? '') === $orderId) {
                return $redemption;
            }
        }

        return null;
    }

    public function countForCustomer(string $couponId, string $email): int
    {
        $email = strtolower($email);
        $count = 0;
        foreach ($this->all() as $redemption) {
            if (($redemption['coupon_id'] ?? '') === $couponId
                && strtolower((string) ($redemption['email'] ?? '')) === $email
                && ($redemption['status'] ?? '') !== 'released') {
                $count++;
            }
        }

        return $count;
    }
}
