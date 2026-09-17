<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Domain\OrderStateMachine;
use PayFlow\Http\Request;
use PayFlow\Http\Response;

final class NotifyController
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * 支付宝异步通知（表单 POST）。成功必须返回纯文本 "success"。
     */
    public function alipay(Request $request): Response
    {
        try {
            $result = $this->app->channels->get('alipay')->parseNotify($request);
        } catch (\Throwable $e) {
            error_log('[PayFlow][alipay-notify] ' . $e->getMessage());

            return Response::text('failure', 400);
        }
        if ($result === null || !$result->paid) {
            return Response::text('failure', 400);
        }

        $this->accept($result->orderNo, $result->channelTradeNo ?? '', $result->amountCents, $result->raw);

        return Response::text('success');
    }

    /**
     * 微信支付 APIv3 异步通知（JSON POST）。
     */
    public function wechat(Request $request): Response
    {
        try {
            $result = $this->app->channels->get('wechat')->parseNotify($request);
        } catch (\Throwable $e) {
            error_log('[PayFlow][wechat-notify] ' . $e->getMessage());

            return Response::json(['code' => 'FAIL', 'message' => '验签失败'], 401);
        }
        if ($result === null || !$result->paid) {
            return Response::json(['code' => 'FAIL', 'message' => '未支付'], 400);
        }

        $this->accept($result->orderNo, $result->channelTradeNo ?? '', $result->amountCents, $result->raw);

        return Response::json(['code' => 'SUCCESS', 'message' => 'OK']);
    }

    /**
     * 加密货币到账回调（签名由链上监听服务/人工调用）。
     */
    public function crypto(Request $request): Response
    {
        try {
            $result = $this->app->channels->get('crypto')->parseNotify($request);
        } catch (\Throwable $e) {
            error_log('[PayFlow][crypto-notify] ' . $e->getMessage());

            return Response::json(['ok' => false, 'error' => '验签失败'], 401);
        }
        if ($result === null || !$result->paid) {
            return Response::json(['ok' => false, 'error' => '无效回调'], 400);
        }

        $this->accept($result->orderNo, $result->channelTradeNo ?? '', $result->amountCents, $result->raw);

        return Response::json(['ok' => true]);
    }

    private function accept(string $orderNo, string $tradeNo, int $amountCents, array $raw): void
    {
        $order = $this->app->orders->findByOrderNo($orderNo);
        if ($order === null) {
            error_log('[PayFlow][notify] 未知订单号: ' . $orderNo);

            return;
        }
        try {
            $this->app->orderService->markPaid((string) $order['id'], $tradeNo, $amountCents, $raw);
        } catch (\Throwable $e) {
            error_log('[PayFlow][notify] 处理失败: ' . $e->getMessage());
        }
    }
}
