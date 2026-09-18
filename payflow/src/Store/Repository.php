<?php

declare(strict_types=1);

namespace PayFlow\Store;

use PayFlow\Support\Id;

abstract class Repository
{
    protected StoreInterface $store;

    public function __construct(StoreFactory $stores)
    {
        $this->store = $stores->store(static::collection());
    }

    abstract protected static function collection(): string;

    /** @return array<string, array> */
    public function all(): array
    {
        return $this->store->all();
    }

    public function find(string $id): ?array
    {
        return $this->store->find($id);
    }

    public function firstBy(string $field, string $value): ?array
    {
        return $this->store->firstBy($field, $value);
    }

    /**
     * @param array<string,string> $filters
     * @return list<array>
     */
    public function query(array $filters = [], int $limit = 0, int $offset = 0, ?string $orderBy = null, string $direction = 'desc'): array
    {
        return $this->store->query($filters, $limit, $offset, $orderBy, $direction);
    }

    /** @param array<string,string> $filters */
    public function countWhere(array $filters = []): int
    {
        return $this->store->count($filters);
    }

    /**
     * @param callable(array):bool $predicate
     * @return list<array>
     */
    public function where(callable $predicate): array
    {
        return $this->store->where($predicate);
    }

    public function insert(array $record): array
    {
        $record['id'] ??= Id::short(static::idPrefix());
        $record['created_at'] ??= date('c');
        $record['updated_at'] = $record['created_at'];

        return $this->store->put($record);
    }

    public function update(string $id, array $patch): ?array
    {
        $record = $this->store->find($id);
        if ($record === null) {
            return null;
        }
        $record = array_merge($record, $patch);
        $record['id'] = $id;
        $record['updated_at'] = date('c');

        return $this->store->put($record);
    }

    public function delete(string $id): void
    {
        $this->store->delete($id);
    }

    public function count(): int
    {
        return $this->store->count();
    }

    protected static function idPrefix(): string
    {
        return '';
    }
}
