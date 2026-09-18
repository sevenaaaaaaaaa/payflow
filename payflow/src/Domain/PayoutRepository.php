<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 提现申请：requested → approved/rejected → paid。
 */
final class PayoutRepository extends Repository
{
    protected static function collection(): string
    {
        return 'payouts';
    }

    protected static function idPrefix(): string
    {
        return 'pyt_';
    }

    /**
     * @return list<array>
     */
    public function forReferral(string $referralId): array
    {
        return $this->query(['referral_id' => $referralId]);
    }

    /**
     * 已申请/已批准但未打款的金额（用于冻结可提现余额）。
     */
    public function reservedCents(string $referralId): int
    {
        $rows = $this->aggregate([
            ['field' => 'referral_id', 'op' => '=', 'value' => $referralId],
            ['field' => 'status', 'op' => 'in', 'value' => ['requested', 'approved']],
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
