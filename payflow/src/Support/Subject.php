<?php

declare(strict_types=1);

namespace PayFlow\Support;

/**
 * 统一主体：email / external_id / tenant。
 * 结账与入站事件都从 payload/query/subject 三处取值。
 */
final class Subject
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $query
     * @return array{email:string,external_id:string,tenant:string}
     */
    public static function from(array $payload, array $query = []): array
    {
        $nested = is_array($payload['subject'] ?? null) ? $payload['subject'] : [];

        return [
            'email' => strtolower(trim((string) ($payload['email'] ?? $nested['email'] ?? $query['email'] ?? ''))),
            'external_id' => trim((string) ($payload['external_id'] ?? $nested['external_id'] ?? $query['external_id'] ?? '')),
            'tenant' => trim((string) ($payload['tenant'] ?? $nested['tenant'] ?? $query['tenant'] ?? '')),
        ];
    }
}
