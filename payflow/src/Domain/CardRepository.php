<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 发卡库存：预生成卡密，购买时自动发一张。
 */
final class CardRepository extends Repository
{
    protected static function collection(): string
    {
        return 'cards';
    }

    protected static function idPrefix(): string
    {
        return 'crd_';
    }

    /**
     * @return list<array>
     */
    public function forProduct(string $productId): array
    {
        return $this->query(['product_id' => $productId]);
    }

    /**
     * @return list<array>
     */
    public function available(string $productId, int $limit = 100): array
    {
        return $this->query(['product_id' => $productId, 'status' => 'available'], max(1, $limit));
    }

    public function stats(string $productId): array
    {
        $out = ['available' => 0, 'issued' => 0, 'disabled' => 0, 'total' => 0];
        foreach ($this->aggregate([['field' => 'product_id', 'op' => '=', 'value' => $productId]], 'status') as $row) {
            $out['total'] += $row['count'];
            if (isset($out[(string) $row['key']])) {
                $out[(string) $row['key']] = $row['count'];
            }
        }

        return $out;
    }

    public function issue(string $cardId, string $orderId): ?array
    {
        $card = $this->find($cardId);
        if ($card === null || ($card['status'] ?? '') !== 'available') {
            return null;
        }

        return $this->update($cardId, [
            'status' => 'issued',
            'order_id' => $orderId,
            'issued_at' => date('c'),
        ]);
    }
}
