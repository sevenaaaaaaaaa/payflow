<?php

declare(strict_types=1);

namespace PayFlow\Store;

/**
 * 集合存储契约：MySQL / SQLite / JSON 三种实现共用。
 */
interface StoreInterface
{
    /** @return array<string, array> */
    public function all(): array;

    public function find(string $id): ?array;

    /**
     * 按字段精确查找（SQL 实现用 LIKE 预筛 + 精确校验，避免全量加载）。
     */
    public function firstBy(string $field, string $value): ?array;

    public function has(string $id): bool;

    /**
     * @param callable(array):bool $predicate
     * @return list<array>
     */
    public function where(callable $predicate): array;

    /**
     * 过滤 + 排序 + 分页查询（等值过滤；MySQL 下推 JSON_EXTRACT，其它实现回退 PHP）。
     *
     * @param array<string,string> $filters
     * @return list<array>
     */
    public function query(array $filters = [], int $limit = 0, int $offset = 0, ?string $orderBy = null, string $direction = 'desc'): array;

    /** @param array<string,string> $filters */
    public function count(array $filters = []): int;

    public function put(array $record): array;

    public function delete(string $id): void;

    /**
     * 读-改-写事务：$mutator 接收当前记录集合并返回新集合。
     */
    public function mutate(callable $mutator): void;

    /** 驱动名：mysql | sqlite | json */
    public function driver(): string;
}
