<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CommissionRepository;
use PayFlow\Domain\CustomerRepository;
use PayFlow\Domain\OrderRepository;
use PayFlow\Domain\SubscriptionRepository;

/**
 * 数据看板：GMV / 转化漏斗 / 订阅健康 / 渠道 / 佣金。
 *
 * 聚合全部下推数据库（MySQL JSON_EXTRACT / SQLite·JSON 回退 PHP），不再整表加载。
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
        $since = date('c', time() - $days * 86400);
        $inRange = [['field' => 'created_at', 'op' => '>=', 'value' => $since]];
        $paidLike = array_merge($inRange, [['field' => 'status', 'op' => 'in', 'value' => ['paid', 'delivered']]]);

        $created = $this->one($this->orders->aggregate($inRange))['count'];
        $paidAgg = $this->one($this->orders->aggregate($paidLike));
        $paid = $paidAgg['count'];
        $gmv = $this->one($this->orders->aggregate($paidLike, null, 'amount_cents'))['sum'];
        $delivered = $this->one($this->orders->aggregate(array_merge($inRange, [['field' => 'status', 'op' => '=', 'value' => 'delivered']])))['count'];
        $refundedCond = array_merge($inRange, [['field' => 'status', 'op' => '=', 'value' => 'refunded']]);
        $refundedAgg = ['count' => $this->one($this->orders->aggregate($refundedCond))['count'], 'sum' => $this->one($this->orders->aggregate($refundedCond, null, 'amount_cents'))['sum']];
        $discount = $this->one($this->orders->aggregate($paidLike, null, 'discount_cents'))['sum'];

        $channels = [];
        foreach ($this->orders->aggregate($paidLike, 'channel', 'amount_cents') as $row) {
            $channels[(string) $row['key']] = ['count' => $row['count'], 'gmv' => $row['sum']];
        }

        $subStats = ['active' => 0, 'past_due' => 0, 'canceled' => 0, 'mrr_cents' => 0];
        foreach ($this->subscriptions->aggregate([], 'status') as $row) {
            if (array_key_exists((string) $row['key'], $subStats)) {
                $subStats[(string) $row['key']] = $row['count'];
            }
        }
        $mrr = 0;
        foreach ($this->subscriptions->aggregate([['field' => 'status', 'op' => '=', 'value' => 'active']], 'interval', 'amount_cents') as $row) {
            $mrr += ((string) $row['key'] === 'year') ? (int) round($row['sum'] / 12) : $row['sum'];
        }
        $subStats['mrr_cents'] = $mrr;

        $newCustomers = $this->one($this->customers->aggregate($inRange))['count'];

        $commission = ['pending' => 0, 'available' => 0, 'paid' => 0, 'reversed' => 0];
        foreach ($this->commissions->aggregate([], 'status', 'amount_cents') as $row) {
            if (array_key_exists((string) $row['key'], $commission)) {
                $commission[(string) $row['key']] = $row['sum'];
            }
        }

        return [
            'days' => $days,
            'created' => $created,
            'paid' => $paid,
            'delivered' => $delivered,
            'refunded' => $refundedAgg['count'],
            'gmv_cents' => $gmv,
            'aov_cents' => $paid > 0 ? (int) round($gmv / $paid) : 0,
            'refund_cents' => $refundedAgg['sum'],
            'discount_cents' => $discount,
            'conversion_rate' => $created > 0 ? round($paid / $created * 100, 1) : 0.0,
            'funnel' => ['created' => $created, 'paid' => $paid, 'delivered' => $delivered],
            'channels' => $channels,
            'subscriptions' => $subStats + ['cancelled' => $subStats['canceled']],
            'new_customers' => $newCustomers,
            'commission' => $commission,
        ];
    }

    /**
     * @return list<array{date:string,gmv_cents:int,orders:int}>
     */
    public function series(int $days = 30): array
    {
        $since = date('c', time() - $days * 86400);
        $rows = $this->orders->groupByDay('created_at', [
            ['field' => 'created_at', 'op' => '>=', 'value' => $since],
            ['field' => 'status', 'op' => 'in', 'value' => ['paid', 'delivered']],
        ], 'amount_cents');

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['key']] = ['gmv_cents' => $row['sum'], 'orders' => $row['count']];
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - $i * 86400);
            $out[] = [
                'date' => $date,
                'gmv_cents' => $byDate[$date]['gmv_cents'] ?? 0,
                'orders' => $byDate[$date]['orders'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{key:string,count:int,sum:int}> $rows
     * @return array{key:string,count:int,sum:int}
     */
    private function one(array $rows): array
    {
        return $rows[0] ?? ['key' => '_all', 'count' => 0, 'sum' => 0];
    }
}
