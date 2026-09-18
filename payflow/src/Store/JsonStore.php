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
