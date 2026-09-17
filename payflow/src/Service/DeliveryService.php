<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\AssetRepository;
use PayFlow\Domain\CardRepository;
use PayFlow\Domain\DownloadRepository;
use PayFlow\Domain\EventRepository;
use PayFlow\Domain\LicenseRepository;
use PayFlow\Domain\OrderStateMachine;
use PayFlow\Domain\ProductRepository;
use PayFlow\Support\Arr;
use PayFlow\Support\Signature;
use RuntimeException;

/**
 * 数字交付：文件托管 + 签名限时/限次下载 + License 密钥。
 */
final class DeliveryService
{
    public function __construct(
        private readonly array $config,
        private readonly AssetRepository $assets,
        private readonly LicenseRepository $licenses,
        private readonly DownloadRepository $downloads,
        private readonly ProductRepository $products,
        private readonly EventRepository $events,
        private readonly string $baseUrl = '',
        private readonly ?CardRepository $cards = null,
    ) {
    }

    public function uploadsDir(): string
    {
        $dir = (string) Arr::get($this->config, 'storage.uploads_dir', PAYFLOW_ROOT . '/uploads');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * @param array{name:string,tmp_name:string,size:int,error:int} $file $_FILES 单项
     */
    public function storeUpload(string $productId, array $file, string $title = '', int $downloadLimit = 0): array
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new RuntimeException('商品不存在');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('上传失败（错误码 ' . (int) ($file['error'] ?? -1) . '）');
        }
        $maxBytes = (int) Arr::get($this->config, 'storage.max_bytes', 200 * 1024 * 1024);
        if ((int) $file['size'] > $maxBytes) {
            throw new RuntimeException('文件超过大小限制');
        }

        $original = (string) $file['name'];
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $safeExt = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
        $stored = bin2hex(random_bytes(12)) . ($safeExt !== '' ? '.' . $safeExt : '');
        $dir = $this->uploadsDir() . '/' . $productId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $target = $dir . '/' . $stored;
        if (!move_uploaded_file((string) $file['tmp_name'], $target) && !rename((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('文件保存失败');
        }
        @chmod($target, 0664);

        $asset = $this->assets->insert([
            'product_id' => $productId,
            'title' => $title !== '' ? $title : $original,
            'original_name' => $original,
            'stored_name' => $stored,
            'path' => $target,
            'size' => (int) $file['size'],
            'mime' => function_exists('mime_content_type') ? (mime_content_type($target) ?: 'application/octet-stream') : 'application/octet-stream',
            'sha256' => hash_file('sha256', $target) ?: '',
            'download_limit' => max(0, $downloadLimit),
        ]);
        $this->events->log('asset.uploaded', ['asset_id' => $asset['id'], 'product_id' => $productId, 'size' => $asset['size']]);

        return $asset;
    }

    public function deleteAsset(string $assetId): void
    {
        $asset = $this->assets->find($assetId);
        if ($asset === null) {
            return;
        }
        if (!empty($asset['path']) && is_file((string) $asset['path'])) {
            @unlink((string) $asset['path']);
        }
        $this->assets->delete($assetId);
        $this->events->log('asset.deleted', ['asset_id' => $assetId]);
    }

    /**
     * @return list<array>
     */
    public function assetsForProduct(string $productId): array
    {
        return $this->assets->forProduct($productId);
    }

    /**
     * @return list<array>
     */
    public function assetsForOrder(array $order): array
    {
        return $this->assets->forProduct((string) ($order['product_id'] ?? ''));
    }

    public function licenseForOrder(array $order): ?array
    {
        return $this->licenses->forOrder((string) $order['id']);
    }

    public function issueLicense(array $order): ?array
    {
        $product = $this->products->find((string) ($order['product_id'] ?? ''));
        if ($product === null || ($product['license_enabled'] ?? false) !== true) {
            return null;
        }
        $existing = $this->licenses->forOrder((string) $order['id']);
        if ($existing !== null) {
            return $existing;
        }
        $prefix = strtoupper((string) ($product['license_prefix'] ?? 'PF'));
        $key = $prefix . '-' . strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
        $license = $this->licenses->insert([
            'product_id' => $order['product_id'],
            'order_id' => $order['id'],
            'order_no' => $order['order_no'] ?? null,
            'customer_id' => $order['customer_id'] ?? null,
            'email' => strtolower((string) ($order['email'] ?? '')),
            'license_key' => $key,
            'status' => 'active',
        ]);
        $this->events->log('license.issued', ['license_id' => $license['id'], 'order_no' => $order['order_no'] ?? null]);

        return $license;
    }

    public function signedDownloadUrl(array $order, array $asset): string
    {
        $ttl = max(60, (int) Arr::get($this->config, 'storage.download_ttl', 3600));
        $token = Signature::encode(['a' => $asset['id'], 'o' => $order['id'], 'exp' => time() + $ttl]);

        return rtrim($this->baseUrl, '/') . '/download/' . $token;
    }

    /**
     * @return array{asset:array,order:array}
     */
    public function resolveDownload(string $token): array
    {
        $data = Signature::decode($token);
        if ($data === null || empty($data['a']) || empty($data['o'])) {
            throw new RuntimeException('下载链接无效或已过期');
        }
        $asset = $this->assets->find((string) $data['a']);
        if ($asset === null) {
            throw new RuntimeException('文件不存在');
        }

        return ['asset' => $asset, 'order_id' => (string) $data['o']];
    }

    public function assertDownloadable(array $asset, array $order): void
    {
        if (!OrderStateMachine::isPaidLike((string) $order['status']) || ($order['status'] ?? '') === OrderStateMachine::REFUNDED) {
            throw new RuntimeException('订单未完成支付');
        }
        if ((string) ($asset['product_id'] ?? '') !== (string) ($order['product_id'] ?? '')) {
            throw new RuntimeException('无权下载该文件');
        }
        $limit = (int) ($asset['download_limit'] ?? 0);
        if ($limit > 0) {
            $row = $this->downloads->forAssetOrder((string) $asset['id'], (string) $order['id']);
            if ($row !== null && (int) $row['count'] >= $limit) {
                throw new RuntimeException('下载次数已用完');
            }
        }
    }

    public function recordDownload(array $asset, array $order): void
    {
        $this->downloads->record((string) $asset['id'], (string) $order['id'], (string) ($order['email'] ?? ''));
        $this->events->log('asset.downloaded', ['asset_id' => $asset['id'], 'order_no' => $order['order_no'] ?? null]);
    }

    /**
     * 发卡：从库存取一张可用卡密发给订单。
     *
     * @return list<string>
     */
    public function issueCards(array $order): array
    {
        if ($this->cards === null) {
            return [];
        }
        $product = $this->products->find((string) ($order['product_id'] ?? ''));
        if ($product === null || ($product['card_enabled'] ?? false) !== true) {
            return [];
        }
        $available = $this->cards->available((string) $product['id'], 1);
        if ($available === []) {
            $this->events->log('card.shortage', ['order_no' => $order['order_no'] ?? null, 'product_id' => $product['id']]);
            error_log('[PayFlow][card] 库存不足: ' . ($order['order_no'] ?? ''));

            return [];
        }
        $card = $this->cards->issue((string) $available[0]['id'], (string) $order['id']);
        if ($card === null) {
            return [];
        }
        $this->events->log('card.issued', ['card_id' => $card['id'], 'order_no' => $order['order_no'] ?? null]);

        return [(string) $card['code']];
    }

    /**
     * @return list<array>
     */
    public function cardsForOrder(array $order): array
    {
        if ($this->cards === null) {
            return [];
        }

        return array_values(array_filter($this->cards->all(), static fn (array $c): bool => ($c['order_id'] ?? '') === ($order['id'] ?? '')));
    }
}
