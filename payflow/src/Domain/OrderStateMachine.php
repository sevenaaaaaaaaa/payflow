<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use RuntimeException;

/**
 * 订单状态机：created → paid → delivered → refunded
 *
 * 附带 canceled / failed 两个终止态。所有状态变更必须经过这里校验，
 * 保证「一笔订单的生命周期可审计」。
 */
final class OrderStateMachine
{
    public const CREATED = 'created';
    public const PAID = 'paid';
    public const DELIVERED = 'delivered';
    public const REFUNDED = 'refunded';
    public const CANCELED = 'canceled';
    public const FAILED = 'failed';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::CREATED => [self::PAID, self::CANCELED, self::FAILED],
        self::PAID => [self::DELIVERED, self::REFUNDED],
        self::DELIVERED => [self::REFUNDED],
        self::REFUNDED => [],
        self::CANCELED => [],
        self::FAILED => [self::CREATED, self::PAID], // 允许失败后重新支付
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (!self::can($from, $to)) {
            throw new RuntimeException("Illegal order transition: {$from} → {$to}");
        }
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::REFUNDED, self::CANCELED], true);
    }

    public static function isPaidLike(string $status): bool
    {
        return in_array($status, [self::PAID, self::DELIVERED, self::REFUNDED], true);
    }

    public static function label(string $status): string
    {
        return [
            self::CREATED => '待支付',
            self::PAID => '已支付',
            self::DELIVERED => '已交付',
            self::REFUNDED => '已退款',
            self::CANCELED => '已取消',
            self::FAILED => '支付失败',
        ][$status] ?? $status;
    }
}
