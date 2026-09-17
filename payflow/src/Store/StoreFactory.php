<?php

declare(strict_types=1);

namespace PayFlow\Store;

use PDO;

/**
 * 存储工厂：按配置选择 MySQL（主）/ SQLite（辅助回退）/ JSON（兜底），
 * 并对每个集合返回统一 StoreInterface。
 */
final class StoreFactory
{
    private ?PDO $pdo = null;
    private string $driver = 'json';
    private ?string $error = null;

    /** @var array<string, StoreInterface> */
    private array $stores = [];

    public function __construct(array $config, private readonly string $dataDir)
    {
        $dbConfig = (array) ($config['database'] ?? []);
        $result = Database::connect($dbConfig, $dataDir);
        $this->pdo = $result['pdo'];
        $this->driver = $result['pdo'] !== null ? $result['driver'] : 'json';
        $this->error = $result['error'];
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function store(string $collection): StoreInterface
    {
        return $this->stores[$collection] ??= ($this->pdo !== null
            ? new SqlStore($this->pdo, $collection, $this->driver)
            : new JsonStore($this->dataDir, $collection));
    }

    public function pdo(): ?PDO
    {
        return $this->pdo;
    }
}
