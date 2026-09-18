<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * 商品/价格仓储。
 *
 * 支持一次性买断与定期订阅两种定价：
 *   type = one_time      → amount_cents 一次性
 *   type = subscription  → amount_cents + interval(month|year) + interval_count + trial_days
 *
 * entitlement 定义「买到什么」：
 *   kind = membership  → 购买即会员，grant membership_level 权益
 *   kind = content     → 数字交付，items[] 是内容 URL 白名单
 */
final class ProductRepository extends Repository
{
    protected static function collection(): string
    {
        return 'products';
    }

    protected static function idPrefix(): string
    {
        return 'prod_';
    }

    /**
     * @return list<array>
     */
    public function active(): array
    {
        $list = array_values(array_filter($this->all(), static fn (array $p): bool => ($p['active'] ?? true) === true));
        usort($list, static fn (array $a, array $b): int => ((int) ($a['sort'] ?? 100)) <=> ((int) ($b['sort'] ?? 100)));

        return $list;
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->firstBy('slug', $slug);
    }

    public function create(array $input): array
    {
        return $this->insert($this->normalize($input));
    }

    public function edit(string $id, array $input): ?array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        return $this->update($id, $this->normalize(array_merge($existing, $input)));
    }

    /**
     * 把表单输入归一化为标准商品结构。
     */
    public function normalize(array $input): array
    {
        $type = ($input['type'] ?? 'one_time') === 'subscription' ? 'subscription' : 'one_time';
        $interval = in_array($input['interval'] ?? null, ['month', 'year'], true) ? $input['interval'] : ($type === 'subscription' ? 'month' : null);

        return [
            'name' => trim((string) ($input['name'] ?? '未命名商品')),
            'slug' => $this->slugify((string) ($input['slug'] ?? $input['name'] ?? 'product')),
            'description' => trim((string) ($input['description'] ?? '')),
            'type' => $type,
            'amount_cents' => max(0, (int) ($input['amount_cents'] ?? 0)),
            'currency' => (string) ($input['currency'] ?? 'CNY'),
            'interval' => $interval,
            'interval_count' => max(1, (int) ($input['interval_count'] ?? 1)),
            'trial_days' => max(0, (int) ($input['trial_days'] ?? 0)),
            'entitlement' => [
                'kind' => ($input['entitlement']['kind'] ?? 'content') === 'membership' ? 'membership' : 'content',
                'membership_level' => (string) ($input['entitlement']['membership_level'] ?? 'member'),
                'duration_days' => max(0, (int) ($input['entitlement']['duration_days'] ?? 0)),
                'items' => $this->normalizeItems($input['entitlement']['items'] ?? []),
            ],
            'active' => (bool) ($input['active'] ?? true),
            'sort' => (int) ($input['sort'] ?? 100),
            'card_enabled' => (bool) ($input['card_enabled'] ?? false),
            'license_enabled' => (bool) ($input['license_enabled'] ?? false),
            'license_prefix' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['license_prefix'] ?? 'PF')) ?: 'PF'),
        ];
    }

    /**
     * @return list<array{title:string,url:string}>
     */
    private function normalizeItems(mixed $items): array
    {
        if (is_string($items)) {
            $items = array_filter(array_map('trim', explode("\n", $items)));
        }
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                if ($item === '') {
                    continue;
                }
                $out[] = ['title' => $item, 'url' => $item];
                continue;
            }
            if (is_array($item) && ($item['url'] ?? '') !== '') {
                $out[] = ['title' => trim((string) ($item['title'] ?? $item['url'])), 'url' => trim((string) $item['url'])];
            }
        }

        return array_values($out);
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'p-' . strtolower(bin2hex(random_bytes(3)));
    }

    public function priceLabel(array $product): string
    {
        $amount = \PayFlow\Support\Money::yuan((int) $product['amount_cents']);
        if (($product['type'] ?? '') !== 'subscription') {
            return $amount;
        }
        $unit = ($product['interval'] ?? 'month') === 'year' ? '年' : '月';

        return $amount . ' / ' . $unit;
    }
}
