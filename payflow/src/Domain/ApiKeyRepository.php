<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * API 密钥：key_id 公开、secret 只在创建时展示一次（存哈希）。
 */
final class ApiKeyRepository extends Repository
{
    protected static function collection(): string
    {
        return 'api_keys';
    }

    protected static function idPrefix(): string
    {
        return 'key_';
    }

    public function findByKeyId(string $keyId): ?array
    {
        foreach ($this->all() as $key) {
            if (hash_equals((string) ($key['key_id'] ?? ''), $keyId)) {
                return $key;
            }
        }

        return null;
    }

    public function touch(string $id): void
    {
        $key = $this->find($id);
        if ($key !== null) {
            $this->update($id, [
                'last_used_at' => date('c'),
                'requests' => ((int) ($key['requests'] ?? 0)) + 1,
            ]);
        }
    }

    /**
     * @return list<array>
     */
    public function recent(): array
    {
        $list = array_values($this->all());
        usort($list, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $list;
    }
}
