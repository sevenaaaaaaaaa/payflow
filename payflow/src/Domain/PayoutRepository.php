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
        return array_values(array_filter($this->all(), static fn (array $p): bool => ($p['referral_id'] ?? '') === $referralId));
    }

    /**
     * 已申请/已批准但未打款的金额（用于冻结可提现余额）。
     */
    public function reservedCents(string $referralId): int
    {
        $sum = 0;
        foreach ($this->all() as $payout) {
            if (($payout['referral_id'] ?? '') === $referralId && in_array($payout['status'] ?? '', ['requested', 'approved'], true)) {
                $sum += (int) ($payout['amount_cents'] ?? 0);
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
