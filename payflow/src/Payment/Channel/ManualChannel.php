<?php

declare(strict_types=1);

namespace PayFlow\Payment\Channel;

use PayFlow\Http\Request;
use PayFlow\Payment\ChannelInterface;
use PayFlow\Payment\NotifyResult;
use PayFlow\Payment\PaymentIntent;
use PayFlow\Support\Money;

/**
 * 人工到账通道：本地联调 / 测试 / 线下收款场景。
 *
 * 收银台展示支付指引，由管理员在后台点「确认到账」完成闭环。
 * 它同时是「一行嵌入三分钟上线」的默认可用通道（无需任何支付资质）。
 */
final class ManualChannel implements ChannelInterface
{
    public function __construct(private readonly array $config = [], private readonly string $baseUrl = '')
    {
    }

    public function id(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? '人工到账');
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function createPayment(array $order): PaymentIntent
    {
        $instructions = sprintf(
            "请向管理员确认转账 %s（订单号 %s）。\n确认到账后系统会自动交付并发送邮件通知。",
            Money::yuan((int) $order['amount_cents']),
            (string) $order['order_no'],
        );

        return new PaymentIntent('manual', null, null, $instructions, (string) $order['order_no']);
    }

    public function parseNotify(Request $request): ?NotifyResult
    {
        return null;
    }

    public function query(string $orderNo): ?NotifyResult
    {
        return null;
    }

    public function refund(array $order, int $amountCents, string $reason = ''): bool
    {
        return true;
    }
}
