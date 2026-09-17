<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CustomerRepository;
use PayFlow\Domain\EntitlementRepository;

/**
 * 权益服务：购买即会员 / 内容 URL 白名单交付。
 */
final class EntitlementService
{
    public function __construct(
        private readonly EntitlementRepository $entitlements,
        private readonly CustomerRepository $customers,
        private readonly string $baseUrl = '',
    ) {
    }

    /**
     * 交付：按订单的商品权益定义落一条 entitlement，并同步会员等级。
     */
    public function grant(array $order): array
    {
        $existing = $this->entitlements->forOrder((string) $order['id']);
        if ($existing !== null) {
            return $existing;
        }

        $entitlement = is_array($order['entitlement'] ?? null) ? $order['entitlement'] : [];
        $kind = ($entitlement['kind'] ?? 'content') === 'membership' ? 'membership' : 'content';
        $items = is_array($entitlement['items'] ?? null) ? $entitlement['items'] : [];

        $expiresAt = null;
        $durationDays = (int) ($entitlement['duration_days'] ?? 0);
        if ($kind === 'membership') {
            $days = $durationDays > 0 ? $durationDays : $this->subscriptionDays($order);
            $expiresAt = $days > 0 ? date('c', strtotime("+{$days} days")) : null;
        }

        $record = $this->entitlements->insert([
            'order_id' => $order['id'],
            'order_no' => $order['order_no'],
            'subscription_id' => $order['subscription_id'] ?? null,
            'customer_id' => $order['customer_id'] ?? null,
            'email' => strtolower((string) ($order['email'] ?? '')),
            'kind' => $kind,
            'level' => (string) ($entitlement['membership_level'] ?? 'member'),
            'items' => $items,
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);

        if ($kind === 'membership' && !empty($order['customer_id'])) {
            $this->customers->update((string) $order['customer_id'], [
                'membership_level' => (string) ($entitlement['membership_level'] ?? 'member'),
                'membership_expires_at' => $expiresAt,
            ]);
        }

        return $record;
    }

    public function revoke(array $order): void
    {
        $existing = $this->entitlements->forOrder((string) $order['id']);
        if ($existing === null) {
            return;
        }
        $this->entitlements->update((string) $existing['id'], [
            'status' => 'revoked',
            'revoked_at' => date('c'),
        ]);
    }

    /**
     * 订阅降级/取消：撤销该订阅下的所有权益，并清空客户会员等级。
     */
    public function revokeForSubscription(array $subscription): int
    {
        $count = 0;
        foreach ($this->entitlements->forSubscription((string) $subscription['id']) as $entitlement) {
            if (($entitlement['status'] ?? 'active') !== 'active') {
                continue;
            }
            $this->entitlements->update((string) $entitlement['id'], [
                'status' => 'revoked',
                'revoked_at' => date('c'),
                'revoke_reason' => 'subscription_downgrade',
            ]);
            $count++;
        }

        $customerId = (string) ($subscription['customer_id'] ?? '');
        if ($customerId !== '' && $count > 0) {
            $this->customers->update($customerId, [
                'membership_level' => null,
                'membership_expires_at' => null,
            ]);
        }

        return $count;
    }

    /**
     * @return list<array>
     */
    public function itemsForOrder(array $order): array
    {
        $entitlement = $this->entitlements->forOrder((string) $order['id']);

        return is_array($entitlement['items'] ?? null) ? $entitlement['items'] : [];
    }

    public function deliveryUrl(array $order): string
    {
        return rtrim($this->baseUrl, '/') . '/d/' . ($order['token'] ?? '');
    }

    public function allows(string $email, string $url): bool
    {
        return $this->entitlements->allows($email, $url);
    }

    private function subscriptionDays(array $order): int
    {
        if (($order['type'] ?? '') !== 'subscription') {
            return 0;
        }

        return ($order['interval'] ?? 'month') === 'year' ? 365 : 31;
    }
}
