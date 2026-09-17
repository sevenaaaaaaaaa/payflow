<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\EventRepository;
use PayFlow\Domain\OrderRepository;
use PayFlow\Domain\OrderStateMachine;
use PayFlow\Domain\ProductRepository;
use PayFlow\Domain\SubscriptionRepository;
use PayFlow\Support\Arr;
use Throwable;

/**
 * 订阅续费引擎。
 *
 * 由于二维码通道没有免密代扣协议，这里的「自动续费」= 到期自动生成续费订单 +
 * 邮件/站内支付链接，按 1/3/5 天重试；宽限期后仍未付则降级（撤销权益）。
 */
final class SubscriptionService
{
    public function __construct(
        private readonly array $config,
        private readonly SubscriptionRepository $subscriptions,
        private readonly OrderRepository $orders,
        private readonly ProductRepository $products,
        private readonly EntitlementService $entitlements,
        private readonly OrderService $orderService,
        private readonly Notifier $notifier,
        private readonly WebhookDispatcher $webhooks,
        private readonly EventRepository $events,
        private readonly string $baseUrl = '',
    ) {
    }

    /**
     * 到期前提醒（不建单，只给续费入口）。
     *
     * @return list<array>
     */
    public function remindExpiring(): array
    {
        $days = max(1, (int) Arr::get($this->config, 'subscription.remind_before_days', 3));
        $done = [];
        foreach ($this->subscriptions->expiringWithinDays($days) as $sub) {
            $url = rtrim($this->baseUrl, '/') . '/checkout?product=' . rawurlencode((string) $sub['product_id']);
            $this->notifier->subscriptionExpiring($sub, $url);
            $this->subscriptions->markReminded((string) $sub['id']);
            $this->events->log('subscription.remind', ['subscription_id' => $sub['id']]);
            $done[] = $sub['id'];
        }

        return $done;
    }

    /**
     * 处理所有到期的订阅：续费 / 重试 / 降级。
     *
     * @return array{due:int, charged:list<string>, failed:list<string>, downgraded:list<string>}
     */
    public function renewDue(): array
    {
        $report = ['due' => 0, 'charged' => [], 'failed' => [], 'downgraded' => []];
        $now = time();
        $graceDays = max(0, (int) Arr::get($this->config, 'subscription.grace_days', 3));
        $offsets = (array) Arr::get($this->config, 'subscription.retry_offsets_days', [1, 3, 5]);

        foreach ($this->subscriptions->dueForRenewal() as $sub) {
            $report['due']++;
            $id = (string) $sub['id'];
            $periodEnd = strtotime((string) ($sub['current_period_end'] ?? 'now')) ?: $now;
            $graceUntil = $periodEnd + $graceDays * 86400;

            if ($now > $graceUntil) {
                $this->downgrade($sub);
                $report['downgraded'][] = $id;
                continue;
            }

            $nextRetry = $sub['next_retry_at'] ?? null;
            if ($nextRetry !== null && $now < (strtotime((string) $nextRetry) ?: 0)) {
                continue;
            }

            $payUrl = $this->ensureRenewalOrder($sub);
            $attempt = ((int) ($sub['failed_attempts'] ?? 0)) + 1;
            $nextIndex = $attempt - 1;
            $nextRetryAt = isset($offsets[$nextIndex]) ? date('c', $now + ((int) $offsets[$nextIndex]) * 86400) : null;

            $sub = $this->subscriptions->markPastDue($id, [
                'failed_attempts' => $attempt,
                'next_retry_at' => $nextRetryAt,
                'grace_until' => date('c', $graceUntil),
                'last_attempt_at' => date('c'),
                'last_failure_reason' => 'awaiting_payment',
            ]) ?? $sub;

            if ($payUrl !== null) {
                $this->notifier->subscriptionPastDue($sub, $payUrl, $attempt);
                $report['failed'][] = $id;
            } else {
                $report['failed'][] = $id;
            }

            $this->events->log('subscription.payment_failed', [
                'subscription_id' => $id,
                'attempt' => $attempt,
                'next_retry_at' => $nextRetryAt,
            ]);
            $this->webhooks->dispatch('subscription.payment_failed', [
                'subscription_id' => $id,
                'attempt' => $attempt,
                'next_retry_at' => $nextRetryAt,
            ]);
        }

        return $report;
    }

    /**
     * 续费成功后由 OrderService 调用：发续费成功通知 + webhook。
     */
    public function onRenewed(array $order, array $subscription): void
    {
        $this->notifier->subscriptionRenewed($order, $subscription);
        $this->webhooks->dispatch('subscription.renewed', [
            'subscription_id' => $subscription['id'] ?? null,
            'order_no' => $order['order_no'] ?? null,
            'current_period_end' => $subscription['current_period_end'] ?? null,
        ]);
        $this->events->log('subscription.renewed', ['subscription_id' => $subscription['id'] ?? null, 'order_no' => $order['order_no'] ?? null]);
    }

    public function cancel(string $subscriptionId, string $reason = 'customer'): ?array
    {
        $sub = $this->subscriptions->find($subscriptionId);
        if ($sub === null) {
            return null;
        }
        $sub = $this->subscriptions->cancel($subscriptionId, $reason);
        if ($reason !== 'customer') {
            $this->entitlements->revokeForSubscription($sub);
        }
        $this->webhooks->dispatch('subscription.canceled', ['subscription_id' => $subscriptionId, 'reason' => $reason]);
        $this->events->log('subscription.canceled', ['subscription_id' => $subscriptionId, 'reason' => $reason]);

        return $sub;
    }

    /**
     * 确保存在一张可支付的续费订单，返回支付链接。
     */
    private function ensureRenewalOrder(array $sub): ?string
    {
        $existing = null;
        $renewalId = (string) ($sub['renewal_order_id'] ?? '');
        if ($renewalId !== '') {
            $candidate = $this->orders->find($renewalId);
            if ($candidate !== null
                && in_array((string) $candidate['status'], [OrderStateMachine::CREATED, OrderStateMachine::FAILED], true)
                && (strtotime((string) $candidate['created_at']) ?: 0) > time() - 7 * 86400) {
                $existing = $candidate;
            }
        }

        if ($existing !== null) {
            return rtrim($this->baseUrl, '/') . '/pay/' . rawurlencode((string) $existing['token']);
        }

        $product = $this->products->find((string) $sub['product_id']);
        if ($product === null || ($product['active'] ?? true) !== true) {
            return null;
        }

        $channel = (string) ($sub['channel'] ?? '');
        if ($channel === '') {
            $enabled = array_keys(array_filter((array) Arr::get($this->config, 'channels', []), static fn ($c): bool => (bool) ($c['enabled'] ?? false)));
            $channel = (string) ($enabled[0] ?? 'manual');
        }

        try {
            $result = $this->orderService->startCheckout(
                (string) $product['id'],
                (string) $sub['email'],
                (string) ($sub['name'] ?? ''),
                $channel,
            );
        } catch (Throwable $e) {
            error_log('[PayFlow][renewal] ' . $e->getMessage());

            return null;
        }

        $order = $result['order'];
        $this->subscriptions->update((string) $sub['id'], ['renewal_order_id' => $order['id']]);

        return rtrim($this->baseUrl, '/') . '/pay/' . rawurlencode((string) $order['token']);
    }

    private function downgrade(array $sub): void
    {
        $this->entitlements->revokeForSubscription($sub);
        $sub = $this->subscriptions->cancel((string) $sub['id'], 'grace_expired') ?? $sub;
        $this->notifier->subscriptionCanceled($sub);
        $this->webhooks->dispatch('subscription.canceled', ['subscription_id' => $sub['id'], 'reason' => 'grace_expired']);
        $this->events->log('subscription.downgraded', ['subscription_id' => $sub['id']]);
    }
}
