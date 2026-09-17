<?php

declare(strict_types=1);

namespace PayFlow\Store;

use PDO;
use PDOException;
use Throwable;

/**
 * 数据库连接与建表（MySQL 主库 / SQLite 辅助回退）。
 */
final class Database
{
    public static function table(): string
    {
        return 'pf_records';
    }

    /**
     * @param array $config database 配置段
     * @return array{pdo:?PDO,driver:string,error:?string}
     */
    public static function connect(array $config, string $dataDir): array
    {
        $driver = strtolower((string) ($config['driver'] ?? 'auto'));

        if (in_array($driver, ['auto', 'mysql'], true) && self::mysqlAvailable($config)) {
            try {
                $pdo = self::mysqlPdo((array) ($config['mysql'] ?? []));
                self::ensureSchema($pdo, 'mysql');

                return ['pdo' => $pdo, 'driver' => 'mysql', 'error' => null];
            } catch (Throwable $e) {
                if ($driver === 'mysql') {
                    // 显式指定 mysql 时不静默回退，报错暴露问题
                    return ['pdo' => null, 'driver' => 'none', 'error' => $e->getMessage()];
                }
                $mysqlError = $e->getMessage();
            }
        }

        if (in_array($driver, ['auto', 'sqlite'], true) && class_exists('PDO')) {
            try {
                $path = (string) ($config['sqlite_path'] ?? ($dataDir . '/payflow.sqlite'));
                if (!is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0775, true);
                }
                $pdo = new PDO('sqlite:' . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA journal_mode=WAL');
                $pdo->exec('PRAGMA busy_timeout=5000');
                self::ensureSchema($pdo, 'sqlite');

                return ['pdo' => $pdo, 'driver' => 'sqlite', 'error' => $mysqlError ?? null];
            } catch (Throwable $e) {
                return ['pdo' => null, 'driver' => 'none', 'error' => $e->getMessage()];
            }
        }

        return ['pdo' => null, 'driver' => 'none', 'error' => 'PDO 不可用'];
    }

    public static function ensureSchema(PDO $pdo, string $driver): void
    {
        $table = self::table();
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
                collection VARCHAR(64) NOT NULL,
                id VARCHAR(160) NOT NULL,
                data LONGTEXT NOT NULL,
                created_at VARCHAR(32) NOT NULL,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (collection, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
                collection TEXT NOT NULL,
                id TEXT NOT NULL,
                data TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (collection, id)
            )");
        }
    }

    private static function mysqlAvailable(array $config): bool
    {
        if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
            return false;
        }
        $mysql = (array) ($config['mysql'] ?? []);
        $env = getenv('MYSQL_ENABLED');

        return !empty($mysql['enabled']) || in_array(strtolower((string) $env), ['1', 'true', 'yes'], true);
    }

    private static function mysqlPdo(array $mysql): PDO
    {
        $host = (string) ($mysql['host'] ?? 'localhost');
        $port = (int) ($mysql['port'] ?? 3306);
        $name = (string) ($mysql['database'] ?? 'payflow');
        $charset = (string) ($mysql['charset'] ?? 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        try {
            $pdo = new PDO($dsn, (string) ($mysql['username'] ?? ''), (string) ($mysql['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('MySQL 连接失败: ' . $e->getMessage(), 0, $e);
        }

        return $pdo;
    }
}
