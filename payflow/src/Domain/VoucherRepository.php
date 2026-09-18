<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 兑换券：凭码免费领取指定商品（赠品/激活码）。
 */
final class VoucherRepository extends Repository
{
    protected static function collection(): string
    {
        return 'vouchers';
    }

    protected static function idPrefix(): string
    {
        return 'vch_';
    }

    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        return $this->firstBy('code', $code);
    }

    /**
     * @return array{ok:bool,reason?:string}
     */
    public function validate(array $voucher): array
    {
        if (($voucher['active'] ?? true) !== true) {
            return ['ok' => false, 'reason' => '兑换券已停用'];
        }
        if (!empty($voucher['expires_at']) && strtotime((string) $voucher['expires_at']) < time()) {
            return ['ok' => false, 'reason' => '兑换券已过期'];
        }
        $max = (int) ($voucher['max_uses'] ?? 1);
        if ($max > 0 && (int) ($voucher['used_count'] ?? 0) >= $max) {
            return ['ok' => false, 'reason' => '兑换券已被使用'];
        }

        return ['ok' => true];
    }

    public function consume(string $id): ?array
    {
        $voucher = $this->find($id);
        if ($voucher === null) {
            return null;
        }

        return $this->update($id, ['used_count' => ((int) ($voucher['used_count'] ?? 0)) + 1, 'last_used_at' => date('c')]);
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 300): array
    {
        return $this->query([], $limit, 0, 'created_at', 'desc');
    }
}
