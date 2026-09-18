<?php

declare(strict_types=1);

namespace PayFlow\Store;

use PDO;
use RuntimeException;

/**
 * 关系型存储（MySQL 主库 / SQLite 辅助）。
 *
 * 采用「集合 + 主键 + JSON 文档」表结构，仓储 API 与 JSON 实现对等，
 * 迁移成本最低、并发与持久性由数据库保证：
 *
 *   pf_records(
 *     collection VARCHAR(64), id VARCHAR(160), data TEXT/LONGTEXT,
 *     created_at VARCHAR(32), updated_at VARCHAR(32),
 *     PRIMARY KEY (collection, id)
 *   )
 *
 * 注：服务器 SQLite 3.7.17、无 UPSERT/JSON1，因此 SQL 只用最基础的
 * INSERT / UPDATE / DELETE / SELECT，过滤在 PHP 侧完成（与 JSON 实现一致）。
 */
final class SqlStore implements StoreInterface
{
    /** @var array<string, array<string, array>> request 级缓存 */
    private array $cache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $collection,
        private readonly string $driver,
    ) {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function all(): array
    {
        if (isset($this->cache[$this->collection])) {
            return $this->cache[$this->collection];
        }
        $stmt = $this->pdo->prepare('SELECT id, data FROM pf_records WHERE collection = :c');
        $stmt->execute(['c' => $this->collection]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (is_array($decoded)) {
                $out[(string) $row['id']] = $decoded;
            }
        }

        return $this->cache[$this->collection] = $out;
    }

    public function find(string $id): ?array
    {
        if (isset($this->cache[$this->collection][$id])) {
            return $this->cache[$this->collection][$id];
        }
        $stmt = $this->pdo->prepare('SELECT data FROM pf_records WHERE collection = :c AND id = :i');
        $stmt->execute(['c' => $this->collection, 'i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        if (!is_array($data)) {
            return null;
        }
        $this->cache[$this->collection][$id] = $data;

        return $data;
    }

    public function has(string $id): bool
    {
        if (isset($this->cache[$this->collection][$id])) {
            return true;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM pf_records WHERE collection = :c AND id = :i LIMIT 1');
        $stmt->execute(['c' => $this->collection, 'i' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * 按字段精确查找：SQL LIKE 预筛候选 → PHP 精确校验；未命中再全量回退，保证正确性。
     */
    public function firstBy(string $field, string $value): ?array
    {
        $value = (string) $value;
        $needle = '%"' . $field . '":"' . addcslashes($value, '%_\\') . '"%';
        $stmt = $this->pdo->prepare('SELECT id, data FROM pf_records WHERE collection = :c AND data LIKE :n LIMIT 50');
        $stmt->execute(['c' => $this->collection, 'n' => $needle]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rec = json_decode((string) $row['data'], true);
            if (is_array($rec) && (string) ($rec[$field] ?? '') === $value) {
                return $rec;
            }
        }
        foreach ($this->all() as $rec) {
            if ((string) ($rec[$field] ?? '') === $value) {
                return $rec;
            }
        }

        return null;
    }

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
        $json = (string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $created = (string) ($record['created_at'] ?? date('c'));
        $updated = (string) ($record['updated_at'] ?? $created);

        $sql = $this->driver === 'mysql'
            ? 'INSERT INTO pf_records (collection, id, data, created_at, updated_at) VALUES (:c, :i, :d, :cr, :up)
               ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)'
            : 'INSERT OR REPLACE INTO pf_records (collection, id, data, created_at, updated_at) VALUES (:c, :i, :d, :cr, :up)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['c' => $this->collection, 'i' => $id, 'd' => $json, 'cr' => $created, 'up' => $updated]);

        $this->cache[$this->collection][$id] = $record;

        return $record;
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pf_records WHERE collection = :c AND id = :i');
        $stmt->execute(['c' => $this->collection, 'i' => $id]);
        unset($this->cache[$this->collection][$id]);
    }

    public function mutate(callable $mutator): void
    {
        $this->pdo->beginTransaction();
        try {
            $records = $this->refresh();
            $next = $mutator($records);
            if (!is_array($next)) {
                throw new RuntimeException('Mutator must return an array');
            }
            $delete = $this->pdo->prepare('DELETE FROM pf_records WHERE collection = :c');
            $delete->execute(['c' => $this->collection]);
            foreach ($next as $id => $record) {
                $record['id'] = $record['id'] ?? $id;
                $this->putRaw($record);
            }
            $this->pdo->commit();
            $this->cache[$this->collection] = $next;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function refresh(): array
    {
        unset($this->cache[$this->collection]);

        return $this->all();
    }

    private function putRaw(array $record): void
    {
        $id = (string) ($record['id'] ?? '');
        $json = (string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $created = (string) ($record['created_at'] ?? date('c'));
        $updated = (string) ($record['updated_at'] ?? $created);
        $stmt = $this->pdo->prepare('INSERT INTO pf_records (collection, id, data, created_at, updated_at) VALUES (:c, :i, :d, :cr, :up)');
        $stmt->execute(['c' => $this->collection, 'i' => $id, 'd' => $json, 'cr' => $created, 'up' => $updated]);
    }
}
