<?php

declare(strict_types=1);

/**
 * 数据迁移：JSON（data/*.json）→ 当前存储（MySQL 主库 / SQLite 回退）。
 *
 *   php payflow/bin/migrate.php            # 导入
 *   php payflow/bin/migrate.php --status   # 只看当前驱动与各集合计数
 *   php payflow/bin/migrate.php --dry-run  # 只统计不写入
 *
 * 幂等：按 id 覆盖写入，可重复执行。
 */

$config = require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Asia/Shanghai'));

$app = new PayFlow\Application($config);
$stores = $app->stores;
$dataDir = (string) $config['data_dir'];

$status = in_array('--status', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

echo '[' . date('c') . "] 存储驱动: {$stores->driver()}\n";
if ($stores->driver() === 'json') {
    echo "当前为 JSON 兜底（数据库不可用）。";
    if ($stores->error() !== null) {
        echo "原因: {$stores->error()}";
    }
    echo "\n";
    if (!$status) {
        echo "请先配置 MySQL/SQLite（config/app.php → database，或 data/config.json）后重试。\n";
        exit(1);
    }
}
if ($stores->driver() === 'sqlite' && $stores->error() !== null) {
    echo "注意：MySQL 不可用已回退 SQLite —— {$stores->error()}\n";
}

$collections = [];
foreach (glob($dataDir . '/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if ($name === 'config' || str_ends_with($name, '.lock')) {
        continue;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    $records = is_array($decoded['records'] ?? null) ? $decoded['records'] : [];
    $collections[$name] = $records;
}

if ($collections === []) {
    echo "没有可迁移的集合。\n";
    exit(0);
}

if ($status) {
    foreach ($collections as $name => $records) {
        echo sprintf("  %-16s JSON=%-5d DB=%d\n", $name, count($records), count($stores->store($name)->all()));
    }
    exit(0);
}

foreach ($collections as $name => $records) {
    $store = $stores->store($name);
    $n = 0;
    foreach ($records as $id => $record) {
        if (!is_array($record)) {
            continue;
        }
        $record['id'] = $record['id'] ?? $id;
        if (!$dryRun) {
            $store->put($record);
        }
        $n++;
    }
    echo sprintf("  %-16s %s %d 条\n", $name, $dryRun ? '将导入' : '已导入', $n);
}

echo $dryRun ? "dry-run 完成（未写入）。\n" : "迁移完成。\n";
