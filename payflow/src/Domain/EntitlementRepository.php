<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 权益仓储：记录「谁因为哪笔订单获得了什么」。
 *
 * kind = membership → level + expires_at
 * kind = content    → items[]（内容 URL 白名单）
 */
final class EntitlementRepository extends Repository
{
    protected static function collection(): string
    {
        return 'entitlements';
    }

    protected static function idPrefix(): string
    {
        return 'ent_';
    }

    /**
     * @return list<array>
     */
    public function forCustomer(string $customerId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $e): bool => ($e['customer_id'] ?? '') === $customerId && ($e['status'] ?? 'active') === 'active',
        ));
    }

    public function forOrder(string $orderId): ?array
    {
        foreach ($this->all() as $entitlement) {
            if (($entitlement['order_id'] ?? '') === $orderId) {
                return $entitlement;
            }
        }

        return null;
    }

    /**
     * @return list<array>
     */
    public function forSubscription(string $subscriptionId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $e): bool => ($e['subscription_id'] ?? '') === $subscriptionId,
        ));
    }

    /**
     * @return list<array>
     */
    public function activeForCustomer(string $customerId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $e): bool => ($e['customer_id'] ?? '') === $customerId && ($e['status'] ?? 'active') === 'active',
        ));
    }

    /**
     * 判断某邮箱是否可访问某内容 URL（白名单 + 前缀匹配）。
     */
    public function allows(string $email, string $url, string $customerId = ''): bool
    {
        $email = strtolower($email);
        foreach ($this->all() as $entitlement) {
            if (($entitlement['status'] ?? 'active') !== 'active') {
                continue;
            }
            $ownerMatches = ($customerId !== '' && ($entitlement['customer_id'] ?? '') === $customerId)
                || strtolower((string) ($entitlement['email'] ?? '')) === $email;
            if (!$ownerMatches) {
                continue;
            }
            if ($this->isExpired($entitlement)) {
                continue;
            }
            foreach ($entitlement['items'] ?? [] as $item) {
                $allowed = (string) ($item['url'] ?? '');
                if ($allowed !== '' && ($url === $allowed || str_starts_with($url, $allowed))) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isExpired(array $entitlement): bool
    {
        $expires = $entitlement['expires_at'] ?? null;
        if (!$expires) {
            return false;
        }

        return strtotime((string) $expires) < time();
    }
}
