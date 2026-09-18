<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 下载计数：按（资产 × 订单）统计，用于限次下载。
 */
final class DownloadRepository extends Repository
{
    protected static function collection(): string
    {
        return 'downloads';
    }

    protected static function idPrefix(): string
    {
        return 'dln_';
    }

    public function forAssetOrder(string $assetId, string $orderId): ?array
    {
        $rows = $this->query(['asset_id' => $assetId, 'order_id' => $orderId], 1);

        return $rows[0] ?? null;
    }

    public function record(string $assetId, string $orderId, string $email = ''): array
    {
        $existing = $this->forAssetOrder($assetId, $orderId);
        if ($existing !== null) {
            return $this->update((string) $existing['id'], [
                'count' => ((int) $existing['count']) + 1,
                'last_at' => date('c'),
            ]) ?? $existing;
        }

        return $this->insert([
            'asset_id' => $assetId,
            'order_id' => $orderId,
            'email' => strtolower($email),
            'count' => 1,
            'last_at' => date('c'),
        ]);
    }
}
