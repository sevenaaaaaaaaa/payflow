<?php

declare(strict_types=1);

/**
 * 存储结构迁移（幂等）：为全文检索补 search_text 列、回填、建 FULLTEXT(ngram) 索引。
 *
 *   php payflow/bin/schema.php
 *
 * - MySQL：新增 search_text MEDIUMTEXT、回填 LOWER(data)、建 ft_search FULLTEXT WITH PARSER ngram
 * - SQLite：仅新增 search_text 列（无 FULLTEXT，检索自动回退 LIKE）
 */

$config = require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Asia/Shanghai'));

use PayFlow\Store\Database;

$dataDir = (string) $config['data_dir'];
$result = Database::connect((array) ($config['database'] ?? []), $dataDir);
if ($result['pdo'] === null) {
    fwrite(STDERR, "数据库不可用：" . ($result['error'] ?? '') . "\n");
    exit(1);
}
$pdo = $result['pdo'];
$driver = $result['driver'];
$table = Database::table();

echo '[' . date('c') . "] 驱动: {$driver}\n";

if ($driver === 'mysql') {
    if (!Database::hasSearchColumn($pdo, $table)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN search_text MEDIUMTEXT NULL AFTER data");
        echo "  + 新增列 search_text\n";
    } else {
        echo "  = search_text 已存在\n";
    }
    $updated = $pdo->exec("UPDATE {$table} SET search_text = LOWER(data) WHERE search_text IS NULL");
    echo "  回填 search_text: " . ($updated === false ? '0' : (string) $updated) . " 行\n";
    echo '  FULLTEXT 索引: ' . (Database::ensureFulltext($pdo, $table) ? '就绪' : '不可用（将回退 LIKE）') . "\n";
} elseif ($driver === 'sqlite') {
    $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'search_text') {
            $has = true;
        }
    }
    if (!$has) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN search_text TEXT");
        echo "  + 新增列 search_text\n";
    }
    echo "  SQLite 无 FULLTEXT，检索自动回退 LIKE\n";
} else {
    echo "  未知驱动，跳过\n";
}

echo "完成。\n";
