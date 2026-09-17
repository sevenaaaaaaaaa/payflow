<?php

declare(strict_types=1);

namespace PayFlow\Payment;

/**
 * 支付意图：通道创建支付后返回给收银台的展示信息。
 *
 * mode:
 *   qrcode   → 展示二维码（content 为二维码内容），前台轮询订单状态
 *   redirect → 跳转第三方收银台（url）
 *   manual   → 人工通道，展示指引文案（instructions）
 */
final class PaymentIntent
{
    public function __construct(
        public readonly string $mode,
        public readonly ?string $content = null,
        public readonly ?string $url = null,
        public readonly ?string $instructions = null,
        public readonly ?string $channelTradeNo = null,
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'content' => $this->content,
            'url' => $this->url,
            'instructions' => $this->instructions,
            'channel_trade_no' => $this->channelTradeNo,
        ];
    }
}
