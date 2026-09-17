<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 佣金记录：pending（冻结）→ available（可提现）→ paid；退款则 reversed。
 */
final class CommissionRepository extends Repository
{
    protected static function collection(): string
    {
        return 'commissions';
    }

    protected static function idPrefix(): string
    {
        return 'com_';
    }

    public function forOrder(string $orderId): ?array
    {
        foreach ($this->all() as $commission) {
            if (($commission['order_id'] ?? '') === $orderId) {
                return $commission;
            }
        }

        return null;
    }

    /**
     * @return list<array>
     */
    public function forReferral(string $referralId): array
    {
        return array_values(array_filter($this->all(), static fn (array $c): bool => ($c['referral_id'] ?? '') === $referralId));
    }

    /**
     * @return list<array>
     */
    public function dueForMaturity(): array
    {
        $now = time();
        return array_values(array_filter($this->all(), static function (array $c) use ($now): bool {
            return ($c['status'] ?? '') === 'pending' && (strtotime((string) ($c['available_at'] ?? 'now')) ?: 0) <= $now;
        }));
    }

    public function sumByStatus(string $referralId, string $status): int
    {
        $sum = 0;
        foreach ($this->all() as $commission) {
            if (($commission['referral_id'] ?? '') === $referralId && ($commission['status'] ?? '') === $status) {
                $sum += (int) ($commission['amount_cents'] ?? 0);
            }
        }

        return $sum;
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
