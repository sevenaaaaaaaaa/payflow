<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\View;
use RuntimeException;

final class CheckoutController
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * 托管收银台页面（buy 按钮 / 弹窗 / 嵌入 iframe 均指向这里）。
     */
    public function page(Request $request): Response
    {
        $product = $this->resolveProduct((string) ($request->query['product'] ?? ''));
        if ($product === null) {
            return Response::html(View::render('error', ['code' => 404, 'message' => '商品不存在或已下架']), 404);
        }

        return Response::html(View::render('checkout', [
            'product' => $product,
            'channels' => $this->app->channels->enabled(),
            'currency' => $product['currency'] ?? 'CNY',
            'embed' => ($request->query['embed'] ?? '') === '1',
            'priceLabel' => $this->app->products->priceLabel($product),
        ]));
    }

    /**
     * 创建订单并唤起支付（checkout.js 调用；返回 JSON）。
     */
    public function create(Request $request): Response
    {
        $payload = $request->payload();
        $product = $this->resolveProduct((string) ($payload['product'] ?? $payload['product_id'] ?? ''));
        if ($product === null) {
            return Response::json(['ok' => false, 'error' => '商品不存在或已下架'], 404);
        }

        $channel = (string) ($payload['channel'] ?? '');
        if ($channel === '') {
            $enabled = $this->app->channels->enabled();
            $channel = $enabled[0]['id'] ?? '';
        }

        try {
            $result = $this->app->orderService->startCheckout(
                (string) $product['id'],
                (string) ($payload['email'] ?? ''),
                (string) ($payload['name'] ?? ''),
                $channel,
                [
                    'coupon' => (string) ($payload['coupon'] ?? ''),
                    'referral' => $this->referralCode($request, $payload),
                ],
            );
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $order = $result['order'];

        return Response::json([
            'ok' => true,
            'order' => $this->app->orderService->publicOrder($order),
            'token' => $order['token'],
            'pay_url' => $this->app->baseUrl('pay/' . $order['token']),
            'intent' => $result['intent'],
        ]);
    }

    /**
     * 优惠券试算（收银台即时校验）。
     */
    public function coupon(Request $request): Response
    {
        $product = $this->resolveProduct((string) ($request->query['product'] ?? ''));
        if ($product === null) {
            return Response::json(['ok' => false, 'error' => '商品不存在'], 404);
        }
        $amount = (int) $product['amount_cents'];
        $result = $this->app->couponService->validate(
            (string) ($request->query['code'] ?? ''),
            $product,
            $amount,
            (string) ($request->query['email'] ?? ''),
        );

        return Response::json([
            'ok' => (bool) ($result['ok'] ?? false),
            'reason' => $result['reason'] ?? null,
            'discount_cents' => (int) ($result['discount_cents'] ?? 0),
            'discount' => \PayFlow\Support\Money::yuan((int) ($result['discount_cents'] ?? 0)),
            'amount_cents' => max(0, $amount - (int) ($result['discount_cents'] ?? 0)),
            'amount' => \PayFlow\Support\Money::yuan(max(0, $amount - (int) ($result['discount_cents'] ?? 0))),
        ]);
    }

    private function referralCode(Request $request, array $payload): string
    {
        $fromPayload = trim((string) ($payload['referral'] ?? ''));
        if ($fromPayload !== '') {
            return $fromPayload;
        }
        $cookie = (string) ($this->app->config['referral']['cookie_name'] ?? 'pf_ref');

        return trim((string) ($_COOKIE[$cookie] ?? ''));
    }

    private function resolveProduct(string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $product = $this->app->products->find($key) ?? $this->app->products->findBySlug($key);
        if ($product === null || ($product['active'] ?? true) !== true) {
            return null;
        }

        return $product;
    }
}
