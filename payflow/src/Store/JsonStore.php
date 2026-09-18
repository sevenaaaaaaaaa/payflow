<?php

declare(strict_types=1);

namespace PayFlow\Store;

use RuntimeException;

/**
 * 单集合 JSON 存储引擎。
 *
 * - 每个集合一个文件：data/db/{collection}.json
 * - 结构：{"records": {id: record, ...}}（对象映射，避免列表重排导致并发写丢失）
 * - 写入用临时文件 + rename 原子替换，配合 flock 防止并发覆盖
 * - 降级兼容：服务器 SQLite 3.7.17 无 UPSERT，故 H1 一律走 JSON 层
 */
final class JsonStore implements StoreInterface
{
    private string $file;
    private ?array $cache = null;

    public function __construct(string $dataDir, private readonly string $collection)
    {
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $this->file = rtrim($dataDir, '/') . '/' . $collection . '.json';
    }


    public function driver(): string
    {
        return 'json';
    }

    /** @return array<string, array> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if (!is_file($this->file)) {
            return $this->cache = [];
        }
        $raw = (string) file_get_contents($this->file);
        $decoded = json_decode($raw, true);
        $records = is_array($decoded['records'] ?? null) ? $decoded['records'] : [];

        return $this->cache = $records;
    }

    public function find(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function firstBy(string $field, string $value): ?array
    {
        foreach ($this->all() as $record) {
            if ((string) ($record[$field] ?? '') === $value) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param callable(array):bool $predicate
     * @return list<array>
     */
    public function where(callable $predicate): array
    {
        $out = [];
        foreach ($this->all() as $record) {
            if ($predicate($record)) {
                $out[] = $record;
            }
        }

        return $out;
    }

    public function query(array $filters = [], int $limit = 0, int $offset = 0, ?string $orderBy = null, string $direction = 'desc'): array
    {
        $rows = array_values(array_filter($this->all(), static function (array $r) use ($filters): bool {
            foreach ($filters as $f => $v) {
                if ((string) ($r[$f] ?? '') !== (string) $v) {
                    return false;
                }
            }

            return true;
        }));
        if ($orderBy !== null) {
            $dir = strtolower($direction) === 'asc' ? 1 : -1;
            usort($rows, static fn (array $a, array $b): int => $dir * strcmp((string) ($a[$orderBy] ?? ''), (string) ($b[$orderBy] ?? '')));
        }
        $rows = array_slice($rows, $offset, $limit > 0 ? $limit : null);

        return $rows;
    }

    public function count(array $filters = []): int
    {
        if ($filters === []) {
            return count($this->all());
        }
        $n = 0;
        foreach ($this->all() as $r) {
            $ok = true;
            foreach ($filters as $f => $v) {
                if ((string) ($r[$f] ?? '') !== (string) $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $n++;
            }
        }

        return $n;
    }

    public function aggregate(array $conditions = [], ?string $groupField = null, ?string $sumField = null): array
    {
        $groups = [];
        foreach ($this->all() as $r) {
            if (!self::matchConditions($r, $conditions)) {
                continue;
            }
            $key = $groupField !== null ? (string) ($r[$groupField] ?? '') : '_all';
            $groups[$key] ??= ['key' => $key, 'count' => 0, 'sum' => 0];
            $groups[$key]['count']++;
            $groups[$key]['sum'] += $sumField !== null ? (int) ($r[$sumField] ?? 0) : 1;
        }
        $out = array_values($groups);
        if ($groupField !== null) {
            usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        }

        return $out;
    }

    public function search(array $fields, string $term, int $limit = 0, int $offset = 0, ?string $orderBy = null, string $direction = 'desc'): array
    {
        $term = strtolower($term);
        $rows = [];
        foreach ($this->all() as $r) {
            if (self::matchSearch($r, $fields, $term)) {
                $rows[] = $r;
            }
        }
        if ($orderBy !== null) {
            $dir = strtolower($direction) === 'asc' ? 1 : -1;
            usort($rows, static fn (array $a, array $b): int => $dir * strcmp((string) ($a[$orderBy] ?? ''), (string) ($b[$orderBy] ?? '')));
        }

        return array_slice($rows, $offset, $limit > 0 ? $limit : null);
    }

    public function searchCount(array $fields, string $term): int
    {
        $term = strtolower($term);
        $n = 0;
        foreach ($this->all() as $r) {
            if (self::matchSearch($r, $fields, $term)) {
                $n++;
            }
        }

        return $n;
    }

    /** @param list<array{field:string,op:string,value:mixed}> $conditions */
    private static function matchConditions(array $r, array $conditions): bool
    {
        foreach ($conditions as $c) {
            $raw = $r[$c['field']] ?? '';
            $actual = is_bool($raw) ? ($raw ? 'true' : 'false') : (string) $raw;
            $value = $c['value'];
            $ok = match ($c['op']) {
                '=' => $actual === (string) $value,
                '!=' => $actual !== (string) $value,
                '>' => $actual > (string) $value,
                '>=' => $actual >= (string) $value,
                '<' => $actual < (string) $value,
                '<=' => $actual <= (string) $value,
                'in' => in_array($actual, array_map('strval', (array) $value), true),
                'like' => str_contains(strtolower($actual), strtolower((string) $value)),
                default => true,
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $fields */
    private static function matchSearch(array $r, array $fields, string $term): bool
    {
        foreach ($fields as $f) {
            if (str_contains(strtolower((string) ($r[$f] ?? '')), $term)) {
                return true;
            }
        }

        return false;
    }

    public function groupByDay(string $dateField, array $conditions = [], ?string $sumField = null): array
    {
        $groups = [];
        foreach ($this->all() as $r) {
            if (!self::matchConditions($r, $conditions)) {
                continue;
            }
            $key = substr((string) ($r[$dateField] ?? ''), 0, 10);
            if ($key === '') {
                continue;
            }
            $groups[$key] ??= ['key' => $key, 'count' => 0, 'sum' => 0];
            $groups[$key]['count']++;
            $groups[$key]['sum'] += $sumField !== null ? (int) ($r[$sumField] ?? 0) : 1;
        }
        ksort($groups);

        return array_values($groups);
    }

    public function queryConditions(array $conditions = [], int $limit = 0, int $offset = 0, ?string $orderBy = null, string $direction = 'desc'): array
    {
        $rows = [];
        foreach ($this->all() as $r) {
            if (self::matchConditions($r, $conditions)) {
                $rows[] = $r;
            }
        }
        if ($orderBy !== null) {
            $dir = strtolower($direction) === 'asc' ? 1 : -1;
            usort($rows, static fn (array $a, array $b): int => $dir * strcmp((string) ($a[$orderBy] ?? ''), (string) ($b[$orderBy] ?? '')));
        }

        return array_slice($rows, $offset, $limit > 0 ? $limit : null);
    }

    public function put(array $record): array
    {
        $id = (string) ($record['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Record must carry a non-empty id');
        }
        $this->mutate(static function (array $records) use ($id, $record): array {
            $records[$id] = $record;

            return $records;
        });

        return $record;
    }

    public function delete(string $id): void
    {
        $this->mutate(static function (array $records) use ($id): array {
            unset($records[$id]);

            return $records;
        });
    }

    /**
     * 读-改-写事务：$mutator 接收当前记录集合并返回新集合。
     */
    public function mutate(callable $mutator): void
    {
        $handle = $this->openLocked();
        try {
            $records = $this->readFromDisk();
            $next = $mutator($records);
            if (!is_array($next)) {
                throw new RuntimeException('Mutator must return an array');
            }
            $this->writeToDisk($next);
            $this->cache = $next;
        } finally {
            $this->release($handle);
        }
    }

    /** @return resource */
    private function openLocked()
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lockFile = $this->file . '.lock';
        $handle = fopen($lockFile, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file: {$lockFile}");
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Cannot acquire write lock');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function readFromDisk(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded['records'] ?? null) ? $decoded['records'] : [];
    }

    private function writeToDisk(array $records): void
    {
        $payload = json_encode(
            ['collection' => $this->collection, 'updated_at' => date('c'), 'records' => $records],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write store: {$this->file}");
        }
        if (!rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot replace store: {$this->file}");
        }
        @chmod($this->file, 0664);
        $this->cache = $records;
    }
}
