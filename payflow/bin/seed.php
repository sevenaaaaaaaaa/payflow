<?php

declare(strict_types=1);

/**
 * 种子数据 / 本地开发配置。
 *
 *   php payflow/bin/seed.php            # 写入演示商品
 *   php payflow/bin/seed.php --dev      # 顺手写入本地开发配置（debug + base_url）
 */

$config = require dirname(__DIR__) . '/bootstrap.php';
$app = new PayFlow\Application($config);

$isDev = in_array('--dev', $argv, true);

if ($isDev) {
    $overrideFile = PAYFLOW_ROOT . '/data/config.json';
    $existing = is_file($overrideFile) ? (json_decode((string) file_get_contents($overrideFile), true) ?: []) : [];
    $existing['app']['env'] = 'local';
    $existing['app']['debug'] = true;
    $existing['app']['base_url'] = 'http://127.0.0.1:8787';
    $existing['app']['base_path'] = '';
    $existing['admin']['username'] = $existing['admin']['username'] ?? 'admin';
    $existing['admin']['password'] = $existing['admin']['password'] ?? 'payflow-dev';
    unset($existing['admin']['token'], $existing['admin']['password_hash']);
    @mkdir(dirname($overrideFile), 0775, true);
    file_put_contents($overrideFile, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "已写入本地开发配置 data/config.json（debug=on, base_url=http://127.0.0.1:8787, 后台 admin / payflow-dev）\n";
}

$samples = [
    [
        'name' => '独立开发者增长手册（电子书）',
        'slug' => 'growth-handbook',
        'description' => '一次性买断，付款后立即获取 PDF 与配套模板。',
        'type' => 'one_time',
        'amount_cents' => 9900,
        'entitlement' => [
            'kind' => 'content',
            'items' => [
                ['title' => '电子书 PDF', 'url' => 'https://example.com/downloads/growth-handbook.pdf'],
                ['title' => '配套 Notion 模板', 'url' => 'https://example.com/downloads/notion-template'],
            ],
        ],
    ],
    [
        'name' => '创作者会员（月度）',
        'slug' => 'creator-membership',
        'description' => '每月自动续费，解锁全部付费内容与会员专区。',
        'type' => 'subscription',
        'amount_cents' => 3900,
        'interval' => 'month',
        'trial_days' => 7,
        'entitlement' => [
            'kind' => 'membership',
            'membership_level' => 'creator',
            'duration_days' => 0,
            'items' => [
                ['title' => '会员专区', 'url' => 'https://example.com/members'],
            ],
        ],
    ],
    [
        'name' => '训练营席位（年付）',
        'slug' => 'bootcamp-annual',
        'description' => '一年期训练营席位，含社群与直播答疑。',
        'type' => 'subscription',
        'amount_cents' => 199900,
        'interval' => 'year',
        'entitlement' => [
            'kind' => 'membership',
            'membership_level' => 'bootcamp',
            'duration_days' => 365,
            'items' => [
                ['title' => '直播回放', 'url' => 'https://example.com/bootcamp/replays'],
            ],
        ],
    ],
];

$created = 0;
foreach ($samples as $sample) {
    if ($app->products->findBySlug($sample['slug']) !== null) {
        echo "跳过（已存在）: {$sample['name']}\n";
        continue;
    }
    $app->products->create($sample);
    $created++;
    echo "已创建: {$sample['name']}\n";
}

echo "\n完成，新增 {$created} 个商品，当前共 {$app->products->count()} 个。\n";
if ($created > 0) {
    echo "本地启动：php -S 127.0.0.1:8787 -t payflow payflow/index.php\n";
    echo "后台入口：http://127.0.0.1:8787/  （admin / payflow-dev）\n";
}
