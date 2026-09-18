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

    /**
     * 能力清单 / 互通发现（其它矩阵产品与集成方读取）。
     */
    public function meta(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $base = rtrim($this->app->baseUrl(), '/');

        return Response::json([
            'ok' => true,
            'product' => 'PayFlow',
            'version' => (string) \PayFlow\Support\Arr::get($this->app->config, 'app.version', '1.0.0'),
            'role' => '收款域（订单事实源 + 现金流中枢）',
            'base_url' => $base,
            'api_base' => $base . '/api/v1',
            'auth' => [
                'bearer' => 'Authorization: Bearer <key_id>.<secret>',
                'hmac' => [
                    'headers' => ['X-PF-Key', 'X-PF-Timestamp', 'X-PF-Signature'],
                    'message' => "timestamp\nMETHOD\nPATH\nBODY",
                    'tolerance_seconds' => 300,
                ],
            ],
            'capabilities' => [
                'checkout', 'subscriptions', 'coupons', 'referrals', 'commissions', 'payouts',
                'digital_delivery', 'licenses', 'payment_links', 'vouchers', 'invoices',
                'analytics', 'webhooks', 'crypto_channel',
            ],
            'endpoints' => [
                ['method' => 'GET', 'path' => '/api/v1/meta', 'desc' => '能力清单'],
                ['method' => 'GET', 'path' => '/api/v1/products', 'desc' => '在售商品'],
                ['method' => 'POST', 'path' => '/api/v1/checkout', 'desc' => '创建订单并返回支付链接'],
                ['method' => 'GET', 'path' => '/api/v1/orders/{orderNo}', 'desc' => '查询订单'],
                ['method' => 'GET|POST', 'path' => '/api/v1/coupons/validate', 'desc' => '优惠券试算'],
                ['method' => 'GET|POST', 'path' => '/api/v1/licenses/validate', 'desc' => 'License 校验'],
                ['method' => 'GET', 'path' => '/api/v1/analytics/summary?days=30', 'desc' => '经营汇总'],
                ['method' => 'GET', 'path' => '/api/v1/events?since=&limit=', 'desc' => '增量拉取出站事件'],
                ['method' => 'POST', 'path' => '/api/v1/events', 'desc' => '入站事件（幂等）'],
            ],
            'events' => [
                'order.paid', 'order.delivered', 'order.refunded',
                'subscription.renewed', 'subscription.payment_failed', 'subscription.canceled',
                'commission.pending', 'commission.available', 'commission.reversed',
                'payout.requested', 'payout.paid', 'referral.click',
            ],
            'channels' => array_map(static fn (array $c): string => $c['id'], $this->app->channels->enabled()),
            'webhook' => [
                'signature' => 'X-PayFlow-Signature = hex(HMAC_SHA256(secret, body))',
                'event_header' => 'X-PayFlow-Event',
                'retry' => 'backoff 30s/1m/2m/4m…，最多 5 次；后台可手动重发',
            ],
            'payment_status_flow' => ['created', 'paid', 'delivered', 'refunded'],
        ]);
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
                [
                    'coupon' => (string) ($payload['coupon'] ?? ''),
                    'referral' => (string) ($payload['referral'] ?? ''),
                    'external_id' => (string) ($payload['external_id'] ?? ''),
                    'tenant' => (string) ($payload['tenant'] ?? ''),
                ],
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

    /**
     * 增量拉取出站事件（Outbox）：GET /api/v1/events?since=&limit=
     */
    public function events(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $since = (string) ($request->query['since'] ?? '');
        $limit = min(500, max(1, $request->int('limit', 100)));
        $rows = $this->app->outbox->since($since, $limit);

        $events = [];
        $cursor = $since;
        foreach ($rows as $row) {
            $events[] = [
                'id' => $row['event_id'] ?? $row['id'],
                'type' => $row['type'] ?? '',
                'version' => (int) ($row['version'] ?? 1),
                'occurred_at' => $row['created_at'] ?? null,
                'source' => 'payflow',
                'subject' => $row['subject'] ?? [],
                'data' => $row['data'] ?? [],
                'idempotency_key' => $row['idempotency_key'] ?? null,
            ];
            $cursor = (string) ($row['created_at'] ?? $cursor);
        }

        return Response::json(['ok' => true, 'events' => $events, 'cursor' => $cursor]);
    }

    /**
     * 入站事件：POST /api/v1/events（HMAC 鉴权；idempotency_key 去重）
     */
    public function eventsIngest(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $payload = $request->payload();
        if (!is_array($payload) || ($payload['type'] ?? '') === '') {
            return Response::json(['ok' => false, 'error' => '缺少事件 type'], 422);
        }
        $result = $this->app->inboundEventService->handle($payload);

        return Response::json($result);
    }

    public function analytics(Request $request): Response
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        return Response::json(['ok' => true, 'summary' => $this->app->analyticsService->summary($request->int('days', 30))]);
    }
}
