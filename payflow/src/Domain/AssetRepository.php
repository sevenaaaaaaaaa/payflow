<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 数字资产（商品可交付的文件）。
 */
final class AssetRepository extends Repository
{
    protected static function collection(): string
    {
        return 'assets';
    }

    protected static function idPrefix(): string
    {
        return 'ast_';
    }

    /**
     * @return list<array>
     */
    public function forProduct(string $productId): array
    {
        return $this->query(['product_id' => $productId]);
    }
}
