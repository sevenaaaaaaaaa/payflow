<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

/**
 * API 日用量：请求数 / 4xx / 5xx，供配额与错误率看板。
 */
final class ApiMetricRepository extends Repository
{
    protected static function collection(): string
    {
        return 'api_metrics';
    }

    protected static function idPrefix(): string
    {
        return 'apm_';
    }

    /**
     * @return array{id:string,day:string,key_id:string,requests:int,errors_4xx:int,errors_5xx:int}
     */
    public function bump(string $keyId, int $status): array
    {
        $day = gmdate('Y-m-d');
        $id = ($keyId !== '' ? $keyId : '_') . ':' . $day;
        $existing = $this->find($id);
        $is4xx = $status >= 400 && $status < 500;
        $is5xx = $status >= 500;
        if ($existing === null) {
            return $this->insert([
                'id' => $id,
                'key_id' => $keyId,
                'day' => $day,
                'requests' => 1,
                'errors_4xx' => $is4xx ? 1 : 0,
                'errors_5xx' => $is5xx ? 1 : 0,
            ]);
        }

        return $this->update($id, [
            'requests' => (int) ($existing['requests'] ?? 0) + 1,
            'errors_4xx' => (int) ($existing['errors_4xx'] ?? 0) + ($is4xx ? 1 : 0),
            'errors_5xx' => (int) ($existing['errors_5xx'] ?? 0) + ($is5xx ? 1 : 0),
        ]) ?? $existing;
    }

    /**
     * @return array{requests:int,errors_4xx:int,errors_5xx:int,error_rate:float}
     */
    public function summarySince(string $sinceDay): array
    {
        $requests = 0;
        $e4 = 0;
        $e5 = 0;
        foreach ($this->queryConditions([
            ['field' => 'day', 'op' => '>=', 'value' => $sinceDay],
        ]) as $row) {
            $requests += (int) ($row['requests'] ?? 0);
            $e4 += (int) ($row['errors_4xx'] ?? 0);
            $e5 += (int) ($row['errors_5xx'] ?? 0);
        }
        $errors = $e4 + $e5;

        return [
            'requests' => $requests,
            'errors_4xx' => $e4,
            'errors_5xx' => $e5,
            'error_rate' => $requests > 0 ? round($errors / $requests * 100, 1) : 0.0,
        ];
    }

    public function pruneOlderThan(int $days = 14): int
    {
        $cutoff = gmdate('Y-m-d', time() - max(1, $days) * 86400);
        $n = 0;
        foreach ($this->all() as $id => $row) {
            if ((string) ($row['day'] ?? '') < $cutoff) {
                $this->delete((string) $id);
                $n++;
            }
        }

        return $n;
    }
}
