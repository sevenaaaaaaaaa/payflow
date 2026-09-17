<?php

declare(strict_types=1);

namespace PayFlow\Support;

final class Id
{
    /**
     * 订单号：PF + YmdHis + 6 位随机，便于人工核对与渠道对账。
     */
    public static function orderNo(): string
    {
        return 'PF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * 通用短 ID（商品/客户/订阅等内部主键）。
     */
    public static function short(string $prefix = ''): string
    {
        return $prefix . bin2hex(random_bytes(8));
    }

    /**
     * 不可猜测的对外令牌（订单支付页 / 交付链接）。
     */
    public static function token(int $bytes = 16): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
