<?php

declare(strict_types=1);

namespace PayFlow\Payment;

use PayFlow\Http\Request;

/**
 * 支付通道适配层契约。
 *
 * 新增渠道只需实现本接口并在 config.channels 注册，
 * 上层结账/订单/交付逻辑零改动（对应 README 能力域的 PaymentChannel 适配层）。
 */
interface ChannelInterface
{
    /** 通道标识，如 alipay / wechat / manual */
    public function id(): string;

    public function label(): string;

    public function isEnabled(): bool;

    /**
     * 创建支付，返回收银台展示所需信息。
     *
     * @param array $order 订单记录
     */
    public function createPayment(array $order): PaymentIntent;

    /**
     * 解析并验签异步通知。验签失败或非本通道通知返回 null。
     */
    public function parseNotify(Request $request): ?NotifyResult;

    /**
     * 主动查单（对账/补偿）。返回 null 表示查询失败或未支付。
     */
    public function query(string $orderNo): ?NotifyResult;

    /**
     * 发起退款（部分通道需商户后台人工处理时返回 false）。
     */
    public function refund(array $order, int $amountCents, string $reason = ''): bool;
}
