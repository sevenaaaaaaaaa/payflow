<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Domain\OrderStateMachine;
use PayFlow\Http\AdminAuth;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\Money;
use PayFlow\Support\View;

final class PaymentController
{
    public function __construct(private readonly Application $app, private readonly AdminAuth $auth)
    {
    }

    /**
     * 订单支付页：二维码 / 跳转 / 人工指引 + 轮询状态。
     */
    public function pay(Request $request): Response
    {
        $order = $this->app->orders->findByToken((string) $request->param('token', ''));
        if ($order === null) {
            return Response::html(View::render('error', ['code' => 404, 'message' => '订单不存在']), 404);
        }

        return Response::html(View::render('pay', [
            'order' => $this->app->orderService->publicOrder($order),
            'token' => $order['token'],
            'intent' => $this->intentFor($order),
            'status' => $order['status'],
            'baseUrl' => $this->app->config['app']['base_url'] ?? '',
            'qrEndpoint' => $this->app->config['app']['qr_endpoint'] ?? '',
        ]));
    }

    /**
     * 订单状态轮询。
     */
    public function status(Request $request): Response
    {
        $order = $this->app->orders->findByToken((string) $request->param('token', ''));
        if ($order === null) {
            return Response::json(['ok' => false, 'error' => '订单不存在'], 404);
        }

        $public = $this->app->orderService->publicOrder($order);
        $public['ok'] = true;
        $public['delivery_url'] = OrderStateMachine::isPaidLike((string) $order['status']) && ($order['status'] ?? '') !== OrderStateMachine::REFUNDED
            ? $this->app->entitlementService->deliveryUrl($order)
            : null;

        return Response::json($public);
    }

    /**
     * 人工到账确认 / 后台补单（需后台令牌）。
     */
    public function confirm(Request $request): Response
    {
        if (!$this->auth->attempt($request)) {
            return Response::json(['ok' => false, 'error' => '未授权'], 401);
        }
        $order = $this->app->orders->findByToken((string) $request->param('token', ''));
        if ($order === null) {
            return Response::json(['ok' => false, 'error' => '订单不存在'], 404);
        }
        try {
            $updated = $this->app->orderService->markPaid((string) $order['id'], 'MANUAL-' . $order['order_no'], (int) $order['amount_cents'], ['source' => 'manual_confirm']);

            return Response::json(['ok' => true, 'order' => $this->app->orderService->publicOrder($updated)]);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * 第三方支付同步回跳页。
     */
    public function returned(Request $request): Response
    {
        $order = $this->app->orders->findByToken((string) $request->param('token', ''));
        if ($order === null) {
            return Response::html(View::render('error', ['code' => 404, 'message' => '订单不存在']), 404);
        }
        // 回跳时主动查单兜底（异步通知可能延迟）
        if (!OrderStateMachine::isPaidLike((string) $order['status'])) {
            $channel = $this->app->channels->get((string) $order['channel']);
            $result = $channel->query((string) $order['order_no']);
            if ($result !== null && $result->paid) {
                $order = $this->app->orderService->markPaid((string) $order['id'], (string) $result->channelTradeNo, $result->amountCents, $result->raw);
            }
        }

        return Response::html(View::render('return', [
            'order' => $this->app->orderService->publicOrder($order),
            'token' => $order['token'],
            'delivery_url' => OrderStateMachine::isPaidLike((string) $order['status']) ? $this->app->entitlementService->deliveryUrl($order) : null,
        ]));
    }

    /**
     * 由订单反推收银台展示的支付意图。
     */
    private function intentFor(array $order): array
    {
        $payload = is_array($order['channel_payload'] ?? null) ? $order['channel_payload'] : [];
        $channel = (string) ($order['channel'] ?? '');

        return match ($channel) {
            'alipay' => [
                'mode' => 'qrcode',
                'content' => (string) ($payload['qr_code'] ?? ''),
                'instructions' => '请使用支付宝扫描二维码完成支付',
            ],
            'wechat' => [
                'mode' => 'qrcode',
                'content' => (string) ($payload['code_url'] ?? ''),
                'instructions' => '请使用微信扫描二维码完成支付',
            ],
            'crypto' => [
                'mode' => 'qrcode',
                'content' => (string) ($payload['uri'] ?? ''),
                'instructions' => sprintf(
                    '请转入 %s %s 至地址 %s（%d 次确认后自动交付）',
                    (string) ($payload['amount_crypto'] ?? ''),
                    (string) ($payload['currency'] ?? ''),
                    (string) ($payload['address'] ?? ''),
                    (int) ($payload['confirmations'] ?? 1),
                ),
            ],
            default => [
                'mode' => 'manual',
                'content' => null,
                'instructions' => sprintf(
                    '请向管理员转账 %s（订单号 %s），确认到账后自动交付。',
                    Money::yuan((int) $order['amount_cents']),
                    (string) $order['order_no'],
                ),
            ],
        };
    }
}
