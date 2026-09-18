<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Domain\OrderStateMachine;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\View;
use RuntimeException;

final class DeliveryController
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * 购买后的专属交付页：列出内容 URL 白名单 / 会员状态。
     */
    public function show(Request $request): Response
    {
        $order = $this->app->orders->findByToken((string) $request->param('token', ''));
        if ($order === null) {
            return Response::html(View::render('error', ['code' => 404, 'message' => '交付链接无效']), 404);
        }
        if (!OrderStateMachine::isPaidLike((string) $order['status']) || ($order['status'] ?? '') === OrderStateMachine::REFUNDED) {
            return Response::html(View::render('error', ['code' => 402, 'message' => '订单尚未完成支付']), 402);
        }

        return Response::html(View::render('delivery', [
            'order' => $this->app->orderService->publicOrder($order),
            'items' => $this->app->entitlementService->itemsForOrder($order),
            'entitlement' => $this->app->entitlements->forOrder((string) $order['id']),
            'assets' => $this->assetsWithUrls($order),
            'license' => $this->app->deliveryService->licenseForOrder($order),
            'cards' => $this->app->deliveryService->cardsForOrder($order),
            'invoice_url' => ($inv = $this->app->invoices->forOrder((string) $order['id'])) !== null
                ? $this->app->invoiceService->urlFor($inv)
                : null,
        ]));
    }

    /**
     * 签名下载：/download/{token}
     */
    public function download(Request $request): Response
    {
        try {
            $resolved = $this->app->deliveryService->resolveDownload((string) $request->param('token', ''));
            $order = $this->app->orders->find($resolved['order_id']);
            if ($order === null) {
                throw new RuntimeException('订单不存在');
            }
            $asset = $resolved['asset'];
            $this->app->deliveryService->assertDownloadable($asset, $order);
            $path = (string) $asset['path'];
            if (!is_file($path)) {
                throw new RuntimeException('文件已不可用');
            }
            $this->app->deliveryService->recordDownload($asset, $order);
            $body = (string) file_get_contents($path);

            return new Response($body, 200, [
                'Content-Type' => (string) ($asset['mime'] ?? 'application/octet-stream'),
                'Content-Length' => (string) strlen($body),
                'Content-Disposition' => 'attachment; filename="' . rawurlencode((string) $asset['original_name']) . '"',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (RuntimeException $e) {
            return Response::html(View::render('error', ['code' => 403, 'message' => $e->getMessage()]), 403);
        }
    }

    /**
     * @return list<array>
     */
    private function assetsWithUrls(array $order): array
    {
        $out = [];
        foreach ($this->app->deliveryService->assetsForOrder($order) as $asset) {
            $asset['download_url'] = $this->app->deliveryService->signedDownloadUrl($order, $asset);
            $out[] = $asset;
        }

        return $out;
    }

    /**
     * 权益校验接口：给内容站/视频站做 URL 白名单访问控制。
     * GET /api/entitlement?email=&url=
     */
    public function check(Request $request): Response
    {
        $email = strtolower((string) ($request->query['email'] ?? ''));
        $url = (string) ($request->query['url'] ?? '');
        if ($email === '' || $url === '') {
            return Response::json(['ok' => false, 'error' => '缺少 email 或 url'], 422);
        }

        return Response::json(['ok' => true, 'allowed' => $this->app->entitlementService->allows($email, $url)]);
    }
}
