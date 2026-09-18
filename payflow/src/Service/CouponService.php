<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CouponRepository;
use PayFlow\Domain\RedemptionRepository;

/**
 * 优惠券校验与应用。
 */
final class CouponService
{
    public function __construct(
        private readonly CouponRepository $coupons,
        private readonly RedemptionRepository $redemptions,
    ) {
    }

    /**
     * 校验并计算折扣。
     *
     * @return array{ok:bool,reason?:string,discount_cents:int,coupon?:array}
     */
    public function validate(?string $code, array $product, int $amountCents, string $email = ''): array
    {
        if ($code === null || trim($code) === '') {
            return ['ok' => false, 'reason' => '请输入优惠券码', 'discount_cents' => 0];
        }
        $coupon = $this->coupons->findByCode($code);
        if ($coupon === null) {
            return ['ok' => false, 'reason' => '优惠券不存在', 'discount_cents' => 0];
        }
        if (($coupon['active'] ?? true) !== true) {
            return ['ok' => false, 'reason' => '优惠券已停用', 'discount_cents' => 0];
        }
        $now = time();
        if (!empty($coupon['starts_at']) && strtotime((string) $coupon['starts_at']) > $now) {
            return ['ok' => false, 'reason' => '优惠券尚未生效', 'discount_cents' => 0];
        }
        if (!empty($coupon['expires_at']) && strtotime((string) $coupon['expires_at']) < $now) {
            return ['ok' => false, 'reason' => '优惠券已过期', 'discount_cents' => 0];
        }
        $max = (int) ($coupon['max_redemptions'] ?? 0);
        if ($max > 0 && (int) ($coupon['redeemed_count'] ?? 0) >= $max) {
            return ['ok' => false, 'reason' => '优惠券已被领完', 'discount_cents' => 0];
        }
        if (($coupon['applies_to'] ?? 'all') === 'products' && !in_array((string) ($product['id'] ?? ''), (array) ($coupon['product_ids'] ?? []), true)) {
            return ['ok' => false, 'reason' => '该优惠券不适用于此商品', 'discount_cents' => 0];
        }
        if ($amountCents < (int) ($coupon['min_amount_cents'] ?? 0)) {
            return ['ok' => false, 'reason' => '未达到优惠券使用门槛', 'discount_cents' => 0];
        }
        $perCustomer = (int) ($coupon['per_customer_limit'] ?? 0);
        if ($perCustomer > 0 && $email !== '' && $this->redemptions->countForCustomer((string) $coupon['id'], $email) >= $perCustomer) {
            return ['ok' => false, 'reason' => '你已使用过该优惠券', 'discount_cents' => 0];
        }

        return ['ok' => true, 'discount_cents' => $this->discountFor($coupon, $amountCents), 'coupon' => $coupon];
    }

    public function discountFor(array $coupon, int $amountCents): int
    {
        if (($coupon['type'] ?? 'percent') === 'fixed') {
            return min($amountCents, (int) $coupon['value']);
        }
        $discount = (int) round($amountCents * (int) $coupon['value'] / 100);

        return min($amountCents, max(0, $discount));
    }

    /**
     * 下单时预占（pending）。失败/取消时释放。
     */
    public function reserve(array $coupon, array $order, int $discountCents): array
    {
        $this->coupons->incrementRedeemed((string) $coupon['id'], 1);

        return $this->redemptions->insert([
            'coupon_id' => $coupon['id'],
            'code' => $coupon['code'],
            'order_id' => $order['id'],
            'order_no' => $order['order_no'] ?? null,
            'email' => strtolower((string) ($order['email'] ?? '')),
            'customer_id' => $order['customer_id'] ?? null,
            'discount_cents' => $discountCents,
            'status' => 'pending',
        ]);
    }

    public function useForOrder(string $orderId): void
    {
        $redemption = $this->redemptions->forOrder($orderId);
        if ($redemption !== null && ($redemption['status'] ?? '') === 'pending') {
            $this->redemptions->update((string) $redemption['id'], ['status' => 'used', 'used_at' => date('c')]);
        }
    }

    public function releaseForOrder(string $orderId): void
    {
        $redemption = $this->redemptions->forOrder($orderId);
        if ($redemption === null || ($redemption['status'] ?? '') === 'released') {
            return;
        }
        $this->redemptions->update((string) $redemption['id'], ['status' => 'released', 'released_at' => date('c')]);
        $this->coupons->incrementRedeemed((string) $redemption['coupon_id'], -1);
    }

}
