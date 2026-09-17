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

    public function has(string $id): bool;

    /**
     * @param callable(array):bool $predicate
     * @return list<array>
     */
    public function where(callable $predicate): array;

    public function put(array $record): array;

    public function delete(string $id): void;

    /**
     * 读-改-写事务：$mutator 接收当前记录集合并返回新集合。
     */
    public function mutate(callable $mutator): void;

    /** 驱动名：mysql | sqlite | json */
    public function driver(): string;
}
