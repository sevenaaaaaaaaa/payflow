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
        return array_values(array_filter($this->all(), static fn (array $c): bool => ($c['product_id'] ?? '') === $productId));
    }

    /**
     * @return list<array>
     */
    public function available(string $productId, int $limit = 100): array
    {
        $out = [];
        foreach ($this->all() as $card) {
            if (($card['product_id'] ?? '') === $productId && ($card['status'] ?? '') === 'available') {
                $out[] = $card;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    public function stats(string $productId): array
    {
        $out = ['available' => 0, 'issued' => 0, 'disabled' => 0, 'total' => 0];
        foreach ($this->all() as $card) {
            if (($card['product_id'] ?? '') !== $productId) {
                continue;
            }
            $out['total']++;
            $status = (string) ($card['status'] ?? 'available');
            if (isset($out[$status])) {
                $out[$status]++;
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
