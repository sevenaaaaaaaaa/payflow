<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CommissionRepository;
use PayFlow\Domain\CustomerRepository;
use PayFlow\Domain\OrderRepository;
use PayFlow\Domain\OrderStateMachine;
use PayFlow\Domain\SubscriptionRepository;

/**
 * 数据看板：GMV / 转化漏斗 / 订阅流失 / 渠道 / 佣金。
 */
final class AnalyticsService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly SubscriptionRepository $subscriptions,
        private readonly CommissionRepository $commissions,
        private readonly CustomerRepository $customers,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(int $days = 30): array
    {
        $since = time() - $days * 86400;
        $range = array_filter($this->orders->all(), static fn (array $o): bool => (strtotime((string) $o['created_at']) ?: 0) >= $since);

        $paid = 0;
        $refunded = 0;
        $gmv = 0;
        $refundAmount = 0;
        $discount = 0;
        $channels = [];
        foreach ($range as $order) {
            $status = (string) $order['status'];
            if (in_array($status, [OrderStateMachine::PAID, OrderStateMachine::DELIVERED], true)) {
                $paid++;
                $gmv += (int) $order['amount_cents'];
                $discount += (int) ($order['discount_cents'] ?? 0);
                $channel = (string) ($order['channel'] ?? 'unknown');
                $channels[$channel] ??= ['count' => 0, 'gmv' => 0];
                $channels[$channel]['count']++;
                $channels[$channel]['gmv'] += (int) $order['amount_cents'];
            } elseif ($status === OrderStateMachine::REFUNDED) {
                $refunded++;
                $refundAmount += (int) $order['amount_cents'];
            }
        }

        $delivered = count(array_filter($range, static fn (array $o): bool => ($o['status'] ?? '') === OrderStateMachine::DELIVERED));
        $created = count($range);

        $subStats = $this->subscriptions->stats();
        $cancelledSubs = count(array_filter($this->subscriptions->all(), static fn (array $s): bool => ($s['status'] ?? '') === 'canceled'));
        $newCustomers = count(array_filter($this->customers->all(), static fn (array $c): bool => (strtotime((string) $c['created_at']) ?: 0) >= $since));

        $commission = [
            'pending' => $this->sumStatus('pending'),
            'available' => $this->sumStatus('available'),
            'paid' => $this->sumStatus('paid'),
            'reversed' => $this->sumStatus('reversed'),
        ];

        return [
            'days' => $days,
            'created' => $created,
            'paid' => $paid,
            'delivered' => $delivered,
            'refunded' => $refunded,
            'gmv_cents' => $gmv,
            'aov_cents' => $paid > 0 ? (int) round($gmv / $paid) : 0,
            'refund_cents' => $refundAmount,
            'discount_cents' => $discount,
            'conversion_rate' => $created > 0 ? round($paid / $created * 100, 1) : 0.0,
            'funnel' => ['created' => $created, 'paid' => $paid, 'delivered' => $delivered],
            'channels' => $channels,
            'subscriptions' => $subStats + ['cancelled' => $cancelledSubs],
            'new_customers' => $newCustomers,
            'commission' => $commission,
        ];
    }

    /**
     * @return list<array{date:string,gmv_cents:int,orders:int}>
     */
    public function series(int $days = 30): array
    {
        $buckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $buckets[date('Y-m-d', time() - $i * 86400)] = ['gmv_cents' => 0, 'orders' => 0];
        }
        foreach ($this->orders->all() as $order) {
            if (!in_array((string) $order['status'], [OrderStateMachine::PAID, OrderStateMachine::DELIVERED], true)) {
                continue;
            }
            $date = date('Y-m-d', strtotime((string) $order['created_at']) ?: 0);
            if (isset($buckets[$date])) {
                $buckets[$date]['gmv_cents'] += (int) $order['amount_cents'];
                $buckets[$date]['orders']++;
            }
        }
        $out = [];
        foreach ($buckets as $date => $row) {
            $out[] = ['date' => $date, 'gmv_cents' => $row['gmv_cents'], 'orders' => $row['orders']];
        }

        return $out;
    }

    private function sumStatus(string $status): int
    {
        $sum = 0;
        foreach ($this->commissions->all() as $commission) {
            if (($commission['status'] ?? '') === $status) {
                $sum += (int) ($commission['amount_cents'] ?? 0);
            }
        }

        return $sum;
    }
}
