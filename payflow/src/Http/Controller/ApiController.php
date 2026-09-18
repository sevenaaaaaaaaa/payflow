<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use RuntimeException;

/**
 * 对外 API v1（Header 鉴权，见 docs/API.md）。
 */
final class ApiController
{
    public function __construct(private readonly Application $app)
    {
    }

    private function authorize(Request $request): ?Response
    {
        $result = $this->app->apiAuth->authenticate($request);
        if (isset($result['error'])) {
            return Response::json(['ok' => false, 'error' => $result['error']], 401);
        }

        return null;
    }

    public function products(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $out = [];
        foreach ($this->app->products->active() as $p) {
            $out[] = [
                'id' => $p['id'],
                'name' => $p['name'],
                'slug' => $p['slug'],
                'type' => $p['type'],
                'amount_cents' => $p['amount_cents'],
                'currency' => $p['currency'] ?? 'CNY',
                'interval' => $p['interval'] ?? null,
            ];
        }

        return Response::json(['ok' => true, 'products' => $out]);
    }

    public function checkout(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $payload = $request->payload();
        $product = $this->app->products->find((string) ($payload['product'] ?? ''))
            ?? $this->app->products->findBySlug((string) ($payload['product'] ?? ''));
        if ($product === null) {
            return Response::json(['ok' => false, 'error' => '商品不存在'], 404);
        }
        $channel = (string) ($payload['channel'] ?? '');
        if ($channel === '') {
            $enabled = $this->app->channels->enabled();
            $channel = (string) ($enabled[0]['id'] ?? '');
        }
        try {
            $result = $this->app->orderService->startCheckout(
                (string) $product['id'],
                (string) ($payload['email'] ?? ''),
                (string) ($payload['name'] ?? ''),
                $channel,
                ['coupon' => (string) ($payload['coupon'] ?? ''), 'referral' => (string) ($payload['referral'] ?? '')],
            );
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $order = $result['order'];

        return Response::json([
            'ok' => true,
            'order' => $this->app->orderService->publicOrder($order),
            'pay_url' => $this->app->baseUrl('pay/' . $order['token']),
            'intent' => $result['intent'],
        ]);
    }

    public function order(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $order = $this->app->orders->findByOrderNo((string) $request->param('orderNo', ''));
        if ($order === null) {
            return Response::json(['ok' => false, 'error' => '订单不存在'], 404);
        }

        return Response::json(['ok' => true, 'order' => $this->app->orderService->publicOrder($order)]);
    }

    public function couponValidate(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $payload = $request->payload();
        $product = $this->app->products->find((string) ($payload['product'] ?? ''));
        if ($product === null) {
            return Response::json(['ok' => false, 'error' => '商品不存在'], 404);
        }
        $result = $this->app->couponService->validate(
            (string) ($payload['code'] ?? ''),
            $product,
            (int) $product['amount_cents'],
            (string) ($payload['email'] ?? ''),
        );

        return Response::json([
            'ok' => (bool) ($result['ok'] ?? false),
            'reason' => $result['reason'] ?? null,
            'discount_cents' => (int) ($result['discount_cents'] ?? 0),
        ]);
    }

    public function licenseValidate(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $payload = $request->payload();
        $key = trim((string) ($payload['license_key'] ?? $request->query['license_key'] ?? ''));
        if ($key === '') {
            return Response::json(['ok' => false, 'error' => '缺少 license_key'], 422);
        }
        $license = $this->app->licenses->findByKey($key);
        if ($license === null) {
            return Response::json(['ok' => true, 'valid' => false]);
        }

        return Response::json(['ok' => true, 'valid' => ($license['status'] ?? '') === 'active', 'license' => [
            'status' => $license['status'] ?? null,
            'product_id' => $license['product_id'] ?? null,
            'order_no' => $license['order_no'] ?? null,
            'issued_at' => $license['created_at'] ?? null,
        ]]);
    }

    public function analytics(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        return Response::json(['ok' => true, 'summary' => $this->app->analyticsService->summary($request->int('days', 30))]);
    }
}
