<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;
use PayFlow\Support\Id;

/**
 * 临时支付链接：任意金额一次性收款（可绑商品带交付，也可纯收款）。
 */
final class PaymentLinkRepository extends Repository
{
    protected static function collection(): string
    {
        return 'payment_links';
    }

    protected static function idPrefix(): string
    {
        return 'plk_';
    }

    public function findByToken(string $token): ?array
    {
        return $this->firstBy('token', $token);
    }

    public function create(array $input): array
    {
        return $this->insert([
            'token' => Id::token(12),
            'title' => trim((string) ($input['title'] ?? '支付链接')),
            'description' => trim((string) ($input['description'] ?? '')),
            'amount_cents' => max(0, (int) ($input['amount_cents'] ?? 0)),
            'currency' => (string) ($input['currency'] ?? 'CNY'),
            'product_id' => ($input['product_id'] ?? '') !== '' ? (string) $input['product_id'] : null,
            'allow_coupon' => (bool) ($input['allow_coupon'] ?? false),
            'max_uses' => max(0, (int) ($input['max_uses'] ?? 0)),
            'used' => 0,
            'expires_at' => ($input['expires_at'] ?? '') !== '' ? (string) $input['expires_at'] : null,
            'active' => (bool) ($input['active'] ?? true),
        ]);
    }

    public function consume(string $id): ?array
    {
        $link = $this->find($id);
        if ($link === null) {
            return null;
        }

        return $this->update($id, ['used' => ((int) ($link['used'] ?? 0)) + 1]);
    }

    /**
     * @return array{ok:bool,reason?:string}
     */
    public function validate(array $link): array
    {
        if (($link['active'] ?? true) !== true) {
            return ['ok' => false, 'reason' => '链接已停用'];
        }
        if (!empty($link['expires_at']) && strtotime((string) $link['expires_at']) < time()) {
            return ['ok' => false, 'reason' => '链接已过期'];
        }
        $max = (int) ($link['max_uses'] ?? 0);
        if ($max > 0 && (int) ($link['used'] ?? 0) >= $max) {
            return ['ok' => false, 'reason' => '链接使用次数已达上限'];
        }

        return ['ok' => true];
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 200): array
    {
        return $this->query([], $limit, 0, 'created_at', 'desc');
    }
}
