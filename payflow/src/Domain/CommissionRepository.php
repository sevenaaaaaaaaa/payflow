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
        return $this->firstBy('order_id', $orderId);
    }

    /**
     * @return list<array>
     */
    public function forReferral(string $referralId): array
    {
        return $this->query(['referral_id' => $referralId]);
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
        $rows = $this->aggregate([
            ['field' => 'referral_id', 'op' => '=', 'value' => $referralId],
            ['field' => 'status', 'op' => '=', 'value' => $status],
        ], null, 'amount_cents');

        return (int) ($rows[0]['sum'] ?? 0);
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 200): array
    {
        return $this->query([], $limit, 0, 'created_at', 'desc');
    }
}
