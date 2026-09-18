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


    public function forOrder(string $orderId): ?array
    {
        return $this->firstBy('order_id', $orderId);
    }

    /**
     * @return list<array>
     */
    public function forSubscription(string $subscriptionId): array
    {
        return $this->query(['subscription_id' => $subscriptionId]);
    }


    /**
     * 判断某邮箱是否可访问某内容 URL（白名单 + 前缀匹配）。
     */
    public function allows(string $email, string $url, string $customerId = ''): bool
    {
        $email = strtolower($email);
        $rows = $this->query(['email' => $email]);
        if ($customerId !== '') {
            $rows = array_merge($rows, $this->query(['customer_id' => $customerId]));
        }
        foreach ($rows as $entitlement) {
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
