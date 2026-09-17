<?php

declare(strict_types=1);

namespace PayFlow\Payment;

/**
 * 通道回调/查单的统一结果。
 */
final class NotifyResult
{
    public function __construct(
        public readonly bool $paid,
        public readonly string $orderNo,
        public readonly ?string $channelTradeNo = null,
        public readonly int $amountCents = 0,
        public readonly array $raw = [],
    ) {
    }
}
