<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 优惠券：满减 / 折扣 / 限时 / 限量。
 *
 * type = percent → value 为折扣百分比（1–100）
 * type = fixed   → value 为减免金额（分）
 */
final class CouponRepository extends Repository
{
    protected static function collection(): string
    {
        return 'coupons';
    }

    protected static function idPrefix(): string
    {
        return 'cpn_';
    }

    public function findByCode(string $code): ?array
    {
        return $this->firstBy('code', strtoupper(trim($code)));
    }

    public function create(array $input): array
    {
        return $this->insert($this->normalize($input));
    }

    public function edit(string $id, array $input): ?array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        return $this->update($id, $this->normalize(array_merge($existing, $input)));
    }

    public function incrementRedeemed(string $id, int $delta = 1): ?array
    {
        $coupon = $this->find($id);
        if ($coupon === null) {
            return null;
        }

        return $this->update($id, ['redeemed_count' => max(0, (int) ($coupon['redeemed_count'] ?? 0) + $delta)]);
    }

    public function normalize(array $input): array
    {
        $type = ($input['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $value = max(0, (int) ($input['value'] ?? 0));
        if ($type === 'percent') {
            $value = min(100, $value);
        }

        return [
            'code' => strtoupper(trim((string) ($input['code'] ?? ''))),
            'description' => trim((string) ($input['description'] ?? '')),
            'type' => $type,
            'value' => $value,
            'min_amount_cents' => max(0, (int) ($input['min_amount_cents'] ?? 0)),
            'max_redemptions' => max(0, (int) ($input['max_redemptions'] ?? 0)),
            'per_customer_limit' => max(0, (int) ($input['per_customer_limit'] ?? 0)),
            'redeemed_count' => (int) ($input['redeemed_count'] ?? 0),
            'starts_at' => ($input['starts_at'] ?? '') !== '' ? (string) $input['starts_at'] : null,
            'expires_at' => ($input['expires_at'] ?? '') !== '' ? (string) $input['expires_at'] : null,
            'applies_to' => ($input['applies_to'] ?? 'all') === 'products' ? 'products' : 'all',
            'product_ids' => array_values(array_filter((array) ($input['product_ids'] ?? []))),
            'active' => (bool) ($input['active'] ?? true),
        ];
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
