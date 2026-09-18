<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

final class CustomerRepository extends Repository
{
    protected static function collection(): string
    {
        return 'customers';
    }

    protected static function idPrefix(): string
    {
        return 'cus_';
    }

    public function findByEmail(string $email): ?array
    {
        return $this->firstBy('email', strtolower(trim($email)));
    }

    /**
     * 幂等 upsert：同邮箱复用客户档案。
     */
    public function findOrCreate(string $email, string $name = ''): array
    {
        $email = strtolower(trim($email));
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            if ($name !== '' && $existing['name'] !== $name) {
                return $this->update((string) $existing['id'], ['name' => $name]) ?? $existing;
            }

            return $existing;
        }

        return $this->insert([
            'email' => $email,
            'name' => trim($name),
            'membership_level' => null,
            'membership_expires_at' => null,
        ]);
    }
}
