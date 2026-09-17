<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\View;
use RuntimeException;

/**
 * 临时支付链接：/l/{token} 收款页 + 创建订单。
 */
final class PaymentLinkController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $link = $this->app->paymentLinkService->resolve((string) $request->param('token', ''));
        if ($link === null) {
            return Response::html(View::render('error', ['code' => 404, 'message' => '支付链接不存在']), 404);
        }
        $check = $this->app->paymentLinkService->validate($link);
        if (($check['ok'] ?? false) !== true) {
            return Response::html(View::render('error', ['code' => 410, 'message' => (string) ($check['reason'] ?? '链接不可用')]), 410);
        }

        return Response::html(View::render('link', [
            'link' => $link,
            'channels' => $this->app->channels->enabled(),
            'allowCoupon' => (bool) ($link['allow_coupon'] ?? false),
        ]));
    }

    public function submit(Request $request): Response
    {
        $payload = $request->payload();
        $link = $this->app->paymentLinkService->resolve((string) ($payload['token'] ?? ''));
        if ($link === null) {
            return Response::json(['ok' => false, 'error' => '支付链接不存在'], 404);
        }
        $check = $this->app->paymentLinkService->validate($link);
        if (($check['ok'] ?? false) !== true) {
            return Response::json(['ok' => false, 'error' => (string) ($check['reason'] ?? '链接不可用')], 410);
        }

        $channel = (string) ($payload['channel'] ?? '');
        if ($channel === '') {
            $enabled = $this->app->channels->enabled();
            $channel = (string) ($enabled[0]['id'] ?? '');
        }

        try {
            $result = $this->app->orderService->startAdHocCheckout(
                $link,
                (string) ($payload['email'] ?? ''),
                (string) ($payload['name'] ?? ''),
                $channel,
                ['coupon' => (string) ($payload['coupon'] ?? ''), 'referral' => (string) ($payload['referral'] ?? '')],
            );
        } catch (RuntimeException $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $this->app->paymentLinkService->consume($link);
        $order = $result['order'];

        return Response::json([
            'ok' => true,
            'order' => $this->app->orderService->publicOrder($order),
            'pay_url' => $this->app->baseUrl('pay/' . $order['token']),
            'intent' => $result['intent'],
        ]);
    }
}
