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
    private bool $connected = false;

    /** @var array<string, StoreInterface> */
    private array $stores = [];

    public function __construct(private readonly array $config, private readonly string $dataDir)
    {
    }

    /**
     * 懒连接：只有真正访问数据时才建立数据库连接（静态资源等请求不再空连 MySQL）。
     */
    private function boot(): void
    {
        if ($this->connected) {
            return;
        }
        $this->connected = true;
        $result = Database::connect((array) ($this->config['database'] ?? []), $this->dataDir);
        $this->pdo = $result['pdo'];
        $this->driver = $result['pdo'] !== null ? $result['driver'] : 'json';
        $this->error = $result['error'];
    }

    public function driver(): string
    {
        $this->boot();

        return $this->driver;
    }

    public function error(): ?string
    {
        $this->boot();

        return $this->error;
    }

    public function store(string $collection): StoreInterface
    {
        $this->boot();

        return $this->stores[$collection] ??= ($this->pdo !== null
            ? new SqlStore($this->pdo, $collection, $this->driver)
            : new JsonStore($this->dataDir, $collection));
    }
}
