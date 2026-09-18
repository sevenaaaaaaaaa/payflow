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

    private ?bool $fulltext = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $collection,
        private readonly string $driver,
    ) {
    }

    private function fulltextReady(): bool
    {
        if ($this->fulltext !== null) {
            return $this->fulltext;
        }
        if ($this->driver !== 'mysql') {
            return $this->fulltext = false;
        }

        return $this->fulltext = Database::hasSearchColumn($this->pdo) && Database::ensureFulltext($this->pdo);
    }

    /**
     * 构造 BOOLEAN 查询；不可靠时返回 null（走 LIKE 兜底）。
     */
    private function booleanQuery(string $term): ?string
    {
        $tokens = preg_split('/[\s,]+/u', mb_strtolower(trim($term))) ?: [];
        $parts = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/[+\-><()~*"@]/u', '', $token) ?? '';
            if ($token === '') {
                continue;
            }
            if (mb_strlen($token) < 2) {
                return null;
            }
            $parts[] = '+' . $token . '*';
        }

        return $parts === [] ? null : implode(' ', $parts);
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

    public function query(array $filters = [], int $limit = 0, int $offset = 0, ?string $orderBy = null, string $direction = 'desc'): array
    {
        if ($this->driver !== 'mysql') {
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

            return array_slice($rows, $offset, $limit > 0 ? $limit : null);
        }

        $params = ['c' => $this->collection];
        [$where, $params] = $this->buildWhere($filters, $params);
        $sql = 'SELECT data FROM ' . Database::table() . ' WHERE ' . $where;
        if ($orderBy !== null && preg_match('/^[A-Za-z0-9_]+$/', $orderBy)) {
            $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
            $sql .= " ORDER BY JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$orderBy}')) {$dir}";
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    public function count(array $filters = []): int
    {
        if ($this->driver !== 'mysql') {
            return $this->driver === 'sqlite' ? count($this->query($filters)) : 0;
        }
        $params = ['c' => $this->collection];
        [$where, $params] = $this->buildWhere($filters, $params);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . Database::table() . ' WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string,string> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters, array $params): array
    {
        $where = ['collection = :c'];
        $i = 0;
        foreach ($filters as $field => $value) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $field)) {
                continue;
            }
            $key = 'f' . $i++;
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$field}')) = :{$key}";
            $params[$key] = (string) $value;
        }

        return [implode(' AND ', $where), $params];
    }

    public function aggregate(array $conditions = [], ?string $groupField = null, ?string $sumField = null): array
    {
        if ($this->driver !== 'mysql') {
            $groups = [];
            foreach ($this->all() as $r) {
                if (!self::matchConditions($r, $conditions)) {
                    continue;
                }
                $key = $groupField !== null ? (string) ($r[$groupField] ?? '') : '_all';
                $groups[$key] ??= ['key' => $key, 'count' => 0, 'sum' => 0];
                $groups[$key]['count']++;
                if ($sumField !== null) {
                    $groups[$key]['sum'] += (int) ($r[$sumField] ?? 0);
                }
            }

            return array_values($groups);
        }

        $params = ['c' => $this->collection];
        [$where, $params] = $this->buildConditions($conditions, $params);
        $groupExpr = ($groupField !== null && preg_match('/^[A-Za-z0-9_]+$/', $groupField))
            ? "JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$groupField}'))"
            : "''";
        $sumExpr = ($sumField !== null && preg_match('/^[A-Za-z0-9_]+$/', $sumField))
            ? "COALESCE(SUM(CAST(JSON_EXTRACT(data, '$.{$sumField}') AS SIGNED)), 0)"
            : 'COUNT(*)';
        $sql = "SELECT {$groupExpr} AS k, COUNT(*) AS c, {$sumExpr} AS s FROM " . Database::table() . " WHERE {$where} GROUP BY k";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'key' => $groupField !== null ? (string) ($row['k'] ?? '') : '_all',
                'count' => (int) $row['c'],
                'sum' => (int) $row['s'],
            ];
        }

        return $out;
    }

    public function search(array $fields, string $term, int $limit = 0, int $offset = 0, ?string $orderBy = null, string $direction = 'desc'): array
    {
        $term = strtolower(trim($term));
        if ($term === '' || $fields === []) {
            return $this->query([], $limit, $offset, $orderBy, $direction);
        }
        if ($this->driver !== 'mysql') {
            $rows = [];
            foreach ($this->all() as $r) {
                foreach ($fields as $f) {
                    if (str_contains(strtolower((string) ($r[$f] ?? '')), $term)) {
                        $rows[] = $r;
                        break;
                    }
                }
            }
            if ($orderBy !== null) {
                $dir = strtolower($direction) === 'asc' ? 1 : -1;
                usort($rows, static fn (array $a, array $b): int => $dir * strcmp((string) ($a[$orderBy] ?? ''), (string) ($b[$orderBy] ?? '')));
            }

            return array_slice($rows, $offset, $limit > 0 ? $limit : null);
        }

        [$or, $params] = $this->buildSearch($fields, $term, ['c' => $this->collection]);
        $out = [];
        if ($offset === 0 && $this->fulltextReady() && ($bool = $this->booleanQuery($term)) !== null) {
            $sql = 'SELECT data FROM ' . Database::table() . ' WHERE collection = :c AND MATCH(search_text) AGAINST (:q IN BOOLEAN MODE)';
            if ($orderBy !== null && preg_match('/^[A-Za-z0-9_]+$/', $orderBy)) {
                $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
                $sql .= " ORDER BY JSON_UNQUOTE(JSON_EXTRACT(data, '\$.{$orderBy}')) {$dir}";
            }
            if ($limit > 0) {
                $sql .= ' LIMIT ' . (int) $limit;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['c' => $this->collection, 'q' => $bool]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $decoded = json_decode((string) $row['data'], true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
            if ($out !== []) {
                return $out;
            }
            // 全文未命中（可能是词中匹配）→ 落到 LIKE 兜底
        }

        $sql = 'SELECT data FROM ' . Database::table() . " WHERE collection = :c AND ({$or})";
        if ($orderBy !== null && preg_match('/^[A-Za-z0-9_]+$/', $orderBy)) {
            $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
            $sql .= " ORDER BY JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$orderBy}')) {$dir}";
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    public function searchCount(array $fields, string $term): int
    {
        $term = strtolower(trim($term));
        if ($term === '' || $fields === []) {
            return $this->count();
        }
        if ($this->driver !== 'mysql') {
            $n = 0;
            foreach ($this->all() as $r) {
                foreach ($fields as $f) {
                    if (str_contains(strtolower((string) ($r[$f] ?? '')), $term)) {
                        $n++;
                        break;
                    }
                }
            }

            return $n;
        }
        if ($this->fulltextReady() && ($bool = $this->booleanQuery($term)) !== null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . Database::table() . ' WHERE collection = :c AND MATCH(search_text) AGAINST (:q IN BOOLEAN MODE)');
            $stmt->execute(['c' => $this->collection, 'q' => $bool]);
            $n = (int) $stmt->fetchColumn();
            if ($n > 0) {
                return $n;
            }
        }
        [$or, $params] = $this->buildSearch($fields, $term, ['c' => $this->collection]);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . Database::table() . " WHERE collection = :c AND ({$or})");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<string> $fields
     * @param array<string,mixed> $params
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildSearch(array $fields, string $term, array $params): array
    {
        $or = [];
        $needle = '%' . addcslashes($term, '%_\\') . '%';
        $i = 0;
        foreach ($fields as $f) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $f)) {
                continue;
            }
            $key = 'q' . $i++;
            $or[] = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$f}'))) LIKE :{$key}";
            $params[$key] = $needle;
        }
        if ($or === []) {
            $or[] = '1=0';
        }

        return [implode(' OR ', $or), $params];
    }

    /**
     * @param list<array{field:string,op:string,value:mixed}> $conditions
     * @param array<string,mixed> $params
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildConditions(array $conditions, array $params): array
    {
        $where = ['collection = :c'];
        $i = 0;
        foreach ($conditions as $c) {
            $field = (string) ($c['field'] ?? '');
            $op = (string) ($c['op'] ?? '=');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $field)) {
                continue;
            }
            $expr = "JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$field}'))";
            if ($op === 'in') {
                $vals = array_map('strval', (array) ($c['value'] ?? []));
                if ($vals === []) {
                    $where[] = '1=0';
                    continue;
                }
                $ph = [];
                foreach ($vals as $v) {
                    $k = 'p' . $i++;
                    $params[$k] = $v;
                    $ph[] = ':' . $k;
                }
                $where[] = "{$expr} IN (" . implode(',', $ph) . ')';
            } elseif ($op === 'like') {
                $k = 'p' . $i++;
                $params[$k] = '%' . addcslashes((string) ($c['value'] ?? ''), '%_\\') . '%';
                $where[] = "{$expr} LIKE :{$k}";
            } elseif (in_array($op, ['=', '!=', '>', '>=', '<', '<='], true)) {
                $k = 'p' . $i++;
                $params[$k] = (string) ($c['value'] ?? '');
                $where[] = "{$expr} {$op} :{$k}";
            }
        }

        return [implode(' AND ', $where), $params];
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

    public function groupByDay(string $dateField, array $conditions = [], ?string $sumField = null): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dateField)) {
            return [];
        }
        if ($this->driver !== 'mysql') {
            return $this->groupByDayFallback($dateField, $conditions, $sumField);
        }
        $params = ['c' => $this->collection];
        [$where, $params] = $this->buildConditions($conditions, $params);
        $dayExpr = "SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$dateField}')), 1, 10)";
        $sumExpr = ($sumField !== null && preg_match('/^[A-Za-z0-9_]+$/', $sumField))
            ? "COALESCE(SUM(CAST(JSON_EXTRACT(data, '$.{$sumField}') AS SIGNED)), 0)"
            : 'COUNT(*)';
        $sql = "SELECT {$dayExpr} AS k, COUNT(*) AS c, {$sumExpr} AS s FROM " . Database::table() . " WHERE {$where} GROUP BY k ORDER BY k ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['key' => (string) ($row['k'] ?? ''), 'count' => (int) $row['c'], 'sum' => (int) $row['s']];
        }

        return $out;
    }

    /**
     * @param list<array{field:string,op:string,value:mixed}> $conditions
     * @return list<array{key:string,count:int,sum:int}>
     */
    private function groupByDayFallback(string $dateField, array $conditions, ?string $sumField): array
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
        if ($this->driver !== 'mysql') {
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

        $params = ['c' => $this->collection];
        [$where, $params] = $this->buildConditions($conditions, $params);
        $sql = 'SELECT data FROM ' . Database::table() . ' WHERE ' . $where;
        if ($orderBy !== null && preg_match('/^[A-Za-z0-9_]+$/', $orderBy)) {
            $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
            $sql .= " ORDER BY JSON_UNQUOTE(JSON_EXTRACT(data, '$.{$orderBy}')) {$dir}";
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (is_array($decoded)) {
                $out[] = $decoded;
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
        $this->writeSearchText($id, $json);

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
        $this->writeSearchText($id, $json);
    }

    private function writeSearchText(string $id, string $json): void
    {
        if (!$this->fulltextReady()) {
            return;
        }
        try {
            $stmt = $this->pdo->prepare('UPDATE ' . Database::table() . ' SET search_text = :s WHERE collection = :c AND id = :i');
            $stmt->execute(['s' => mb_strtolower($json), 'c' => $this->collection, 'i' => $id]);
        } catch (\Throwable $e) {
            // 不影响主流程
        }
    }
}
