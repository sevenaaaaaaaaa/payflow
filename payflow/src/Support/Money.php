<?php

declare(strict_types=1);

namespace PayFlow\Support;

final class Money
{
    /**
     * 金额一律以「分」为最小单位在内部流转，避免浮点误差。
     * 这里仅做展示层格式化。
     */
    public static function yuan(int $cents, string $symbol = '¥'): string
    {
        return $symbol . number_format($cents / 100, 2, '.', ',');
    }

    /**
     * 人类可读输入（元）转分，支持 "19.9" / "19.90" / "19"。
     */
    public static function toCents(string|int|float $yuan): int
    {
        return (int) round(((float) $yuan) * 100);
    }
}
