<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;
use PayFlow\Support\Id;

final class OrderRepository extends Repository
{
    protected static function collection(): string
    {
        return 'orders';
    }

    protected static function idPrefix(): string
    {
        return 'ord_';
    }

    public function findByToken(string $token): ?array
    {
        return $this->firstBy('token', $token);
    }

    public function findByOrderNo(string $orderNo): ?array
    {
        return $this->firstBy('order_no', $orderNo);
    }


    public function create(array $attributes): array
    {
        return $this->insert(array_merge([
            'order_no' => Id::orderNo(),
            'token' => Id::token(),
            'status' => OrderStateMachine::CREATED,
            'channel' => null,
            'channel_trade_no' => null,
            'channel_payload' => null,
            'paid_at' => null,
            'delivered_at' => null,
            'refunded_at' => null,
            'canceled_at' => null,
            'failure_reason' => null,
            'subscription_id' => null,
            'entitlement' => null,
            'events' => [],
        ], $attributes));
    }

    /**
     * 追加一条状态流转事件（订单内嵌审计轨迹）。
     */
    public function appendEvent(string $id, array $event): ?array
    {
        $order = $this->find($id);
        if ($order === null) {
            return null;
        }
        $events = is_array($order['events'] ?? null) ? $order['events'] : [];
        $events[] = array_merge(['at' => date('c')], $event);

        return $this->update($id, ['events' => $events]);
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 50): array
    {
        return $this->query([], $limit, 0, 'created_at', 'desc');
    }

    public function stats(): array
    {
        $stats = ['total' => 0, 'paid' => 0, 'revenue_cents' => 0, 'refunded_cents' => 0, 'subscriptions' => 0];
        foreach ($this->all() as $order) {
            $stats['total']++;
            if (in_array($order['status'] ?? '', [OrderStateMachine::PAID, OrderStateMachine::DELIVERED], true)) {
                $stats['paid']++;
                $stats['revenue_cents'] += (int) ($order['amount_cents'] ?? 0);
            }
            if (($order['status'] ?? '') === OrderStateMachine::REFUNDED) {
                $stats['refunded_cents'] += (int) ($order['amount_cents'] ?? 0);
            }
            if (($order['type'] ?? '') === 'subscription') {
                $stats['subscriptions']++;
            }
        }

        return $stats;
    }
}
