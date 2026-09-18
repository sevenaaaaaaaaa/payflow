<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 订阅仓储：定期扣费 + 自动续费 + 失败重试 + 宽限期降级。
 *
 * 状态：active（正常）→ past_due（续费失败重试中）→ canceled（宽限期后降级/手动取消）
 */
final class SubscriptionRepository extends Repository
{
    protected static function collection(): string
    {
        return 'subscriptions';
    }

    protected static function idPrefix(): string
    {
        return 'sub_';
    }

    public function start(array $order): array
    {
        $interval = ($order['interval'] ?? 'month') === 'year' ? '+1 year' : '+1 month';
        $existing = $this->findByCustomerAndProduct((string) $order['customer_id'], (string) $order['product_id']);

        if ($existing !== null && in_array($existing['status'] ?? '', ['active', 'past_due'], true)) {
            $base = max(time(), strtotime((string) ($existing['current_period_end'] ?? 'now')) ?: time());

            return $this->update((string) $existing['id'], [
                'status' => 'active',
                'current_period_end' => date('c', strtotime($interval, $base)),
                'failed_attempts' => 0,
                'next_retry_at' => null,
                'grace_until' => null,
                'renewal_order_id' => null,
                'last_order_id' => $order['id'] ?? null,
                'last_charge_at' => date('c'),
                'started_at' => $existing['started_at'] ?? date('c'),
            ]) ?? $existing;
        }

        return $this->insert([
            'customer_id' => $order['customer_id'] ?? null,
            'product_id' => $order['product_id'] ?? null,
            'product_name' => $order['product_name'] ?? '',
            'email' => $order['email'] ?? '',
            'name' => $order['name'] ?? '',
            'status' => 'active',
            'channel' => $order['channel'] ?? null,
            'currency' => $order['currency'] ?? 'CNY',
            'interval' => $order['interval'] ?? 'month',
            'amount_cents' => (int) ($order['amount_cents'] ?? 0),
            'auto_renew' => true,
            'current_period_end' => date('c', strtotime($interval)),
            'failed_attempts' => 0,
            'next_retry_at' => null,
            'grace_until' => null,
            'renewal_order_id' => null,
            'last_order_id' => $order['id'] ?? null,
            'last_charge_at' => date('c'),
            'started_at' => date('c'),
        ]);
    }

    public function findByCustomerAndProduct(string $customerId, string $productId): ?array
    {
        foreach ($this->all() as $sub) {
            if (($sub['customer_id'] ?? '') === $customerId && ($sub['product_id'] ?? '') === $productId) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * 到期（含宽限期内）需要处理的订阅。
     *
     * @return list<array>
     */
    public function dueForRenewal(int $withinSeconds = 0): array
    {
        $threshold = time() + $withinSeconds;
        $out = [];
        foreach ($this->all() as $sub) {
            if (!in_array($sub['status'] ?? '', ['active', 'past_due'], true)) {
                continue;
            }
            if (($sub['auto_renew'] ?? true) !== true) {
                continue;
            }
            $end = strtotime((string) ($sub['current_period_end'] ?? 'now')) ?: 0;
            if ($end <= $threshold) {
                $out[] = $sub;
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['current_period_end'], (string) $b['current_period_end']));

        return $out;
    }

    /**
     * 即将到期（尚未到期）用于到期前提醒。
     *
     * @return list<array>
     */
    public function expiringWithinDays(int $days): array
    {
        $now = time();
        $threshold = $now + $days * 86400;
        $out = [];
        foreach ($this->all() as $sub) {
            if (($sub['status'] ?? '') !== 'active') {
                continue;
            }
            $end = strtotime((string) ($sub['current_period_end'] ?? 'now')) ?: 0;
            if ($end > $now && $end <= $threshold && empty($sub['reminded_at'])) {
                $out[] = $sub;
            }
        }

        return $out;
    }

    public function markPastDue(string $id, array $patch): ?array
    {
        $sub = $this->find($id);
        if ($sub === null) {
            return null;
        }

        return $this->update($id, array_merge([
            'status' => 'past_due',
            'failed_attempts' => ((int) ($sub['failed_attempts'] ?? 0)) + 1,
        ], $patch));
    }

    public function markReminded(string $id): ?array
    {
        return $this->update($id, ['reminded_at' => date('c')]);
    }

    public function cancel(string $id, string $reason = ''): ?array
    {
        return $this->update($id, [
            'status' => 'canceled',
            'auto_renew' => false,
            'canceled_at' => date('c'),
            'cancel_reason' => $reason,
        ]);
    }



    public function stats(): array
    {
        $out = ['active' => 0, 'past_due' => 0, 'canceled' => 0, 'mrr_cents' => 0];
        foreach ($this->all() as $sub) {
            $status = (string) ($sub['status'] ?? '');
            if (isset($out[$status])) {
                $out[$status]++;
            }
            if ($status === 'active') {
                $amount = (int) ($sub['amount_cents'] ?? 0);
                $out['mrr_cents'] += ($sub['interval'] ?? 'month') === 'year' ? (int) round($amount / 12) : $amount;
            }
        }

        return $out;
    }
}
