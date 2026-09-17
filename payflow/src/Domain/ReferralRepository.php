<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;
use PayFlow\Support\Id;

/**
 * 推荐人（分销大使）：推荐码 + 大使层级 + 佣金比例。
 *
 * 余额不在此维护（避免漂移），由 CommissionService 按佣金记录实时汇总。
 */
final class ReferralRepository extends Repository
{
    protected static function collection(): string
    {
        return 'referrals';
    }

    protected static function idPrefix(): string
    {
        return 'ref_';
    }

    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        foreach ($this->all() as $referral) {
            if (strtoupper((string) ($referral['code'] ?? '')) === $code) {
                return $referral;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        foreach ($this->all() as $referral) {
            if (strtolower((string) ($referral['email'] ?? '')) === $email) {
                return $referral;
            }
        }

        return null;
    }

    public function findByCustomer(string $customerId): ?array
    {
        foreach ($this->all() as $referral) {
            if (($referral['customer_id'] ?? '') === $customerId) {
                return $referral;
            }
        }

        return null;
    }

    /**
     * 获取或创建某邮箱的推荐人（结账时自动成为推荐人）。
     */
    public function findOrCreate(string $email, string $name = '', string $customerId = ''): array
    {
        $email = strtolower(trim($email));
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        return $this->insert([
            'code' => $this->generateCode($email),
            'email' => $email,
            'name' => trim($name),
            'customer_id' => $customerId,
            'level' => 'standard',
            'commission_rate' => null,   // null = 用层级默认值
            'clicks' => 0,
            'signups' => 0,
            'orders' => 0,
            'total_commission_cents' => 0,
            'active' => true,
        ]);
    }

    public function increment(string $id, string $field, int $delta = 1): ?array
    {
        $referral = $this->find($id);
        if ($referral === null) {
            return null;
        }

        return $this->update($id, [$field => max(0, (int) ($referral[$field] ?? 0) + $delta)]);
    }

    public function generateCode(string $seed): string
    {
        $base = strtoupper(preg_replace('/[^a-z0-9]/i', '', strstr($seed, '@', true) ?: $seed) ?? '');
        $base = substr($base, 0, 6);
        if ($base === '') {
            $base = 'PF';
        }
        do {
            $code = $base . strtoupper(substr(Id::short(), 0, 4));
        } while ($this->findByCode($code) !== null);

        return $code;
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
