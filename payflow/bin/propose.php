<?php

declare(strict_types=1);

/**
 * PayFlow · AI 提议（自我进化闭环的「提议」环节）
 *
 *   php payflow/bin/propose.php
 *
 * 读取：bin/audit.php 报告 + 经营汇总 + docs/BACKLOG.md
 * 产出：docs/PROPOSALS.md（结构化提案，供人工评审）
 *
 * 原则：**只提议，不执行**。模型输出不得伪装成已执行结果；提案需人工批准后进入 BACKLOG。
 * 未配置 AI（app.ai.enabled=false 或 api_key 为空）时安全退出。
 */

$config = require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Asia/Shanghai'));

use PayFlow\Support\Arr;
use PayFlow\Support\HttpClient;

$ai = (array) ($config['ai'] ?? []);
if (empty($ai['enabled']) || (string) ($ai['api_key'] ?? '') === '') {
    echo "AI 未启用或缺少 api_key（data/config.json → ai）。跳过。\n";
    exit(0);
}

$root = dirname(__DIR__);
$app = new PayFlow\Application($config);

echo '[' . date('c') . "] 收集上下文…\n";
$audit = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/audit.php') . ' 2>/dev/null');
$summary = $app->analyticsService->summary(30);
$backlog = is_file($root . '/../docs/BACKLOG.md') ? (string) file_get_contents($root . '/../docs/BACKLOG.md') : '';

$context = "## 自审计报告\n" . $audit
    . "\n## 近 30 天经营指标（JSON）\n" . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    . "\n## 现有施工图\n" . mb_substr($backlog, 0, 4000);

$system = '你是 PayFlow（PHP/MySQL 收款与变现系统）的工程与产品顾问。基于给定的审计报告、经营指标与施工图，'
    . '提出最多 5 条最有价值的改进提案。只输出合法 JSON 数组，每个元素：'
    . '{"title":"","problem":"","evidence":"","proposal":"","impact":"","cost":"S|M|L","priority":"T0|T1|T2"}。'
    . '不要输出任何解释性文字或 Markdown 代码块。';

$body = [
    'model' => (string) Arr::get($ai, 'model', 'deepseek-chat'),
    'temperature' => 0.4,
    'max_tokens' => 2000,
    'messages' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $context],
    ],
];

try {
    $resp = HttpClient::request(
        'POST',
        rtrim((string) Arr::get($ai, 'base_url', 'https://api.deepseek.com/v1'), '/') . '/chat/completions',
        (string) json_encode($body, JSON_UNESCAPED_UNICODE),
        ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . (string) $ai['api_key']],
        (int) Arr::get($ai, 'timeout', 60),
    );
} catch (Throwable $e) {
    fwrite(STDERR, '调用失败：' . $e->getMessage() . "\n");
    exit(1);
}

$decoded = json_decode($resp['body'], true);
$content = (string) (Arr::get($decoded, 'choices.0.message.content') ?? '');
$content = trim((string) preg_replace('/^```(json)?|```$/m', '', $content));
$proposals = json_decode($content, true);
if (!is_array($proposals)) {
    fwrite(STDERR, "模型未返回合法 JSON：\n" . mb_substr($content, 0, 500) . "\n");
    exit(1);
}

$out = "# PayFlow · AI 提案（待人工评审）\n\n> 生成时间：" . date('c') . " · 模型：{$body['model']}\n"
    . "> 说明：AI 只提议，不执行。批准后移入 `docs/BACKLOG.md` 再实现。\n\n";
foreach ($proposals as $i => $p) {
    $out .= sprintf(
        "## %d. %s\n\n- 优先级：%s · 成本：%s\n- 问题：%s\n- 证据：%s\n- 提案：%s\n- 影响：%s\n\n",
        $i + 1,
        (string) ($p['title'] ?? '未命名'),
        (string) ($p['priority'] ?? '-'),
        (string) ($p['cost'] ?? '-'),
        (string) ($p['problem'] ?? ''),
        (string) ($p['evidence'] ?? ''),
        (string) ($p['proposal'] ?? ''),
        (string) ($p['impact'] ?? ''),
    );
}

$target = $root . '/../docs/PROPOSALS.md';
file_put_contents($target, $out);
echo "已生成 " . count($proposals) . " 条提案 → docs/PROPOSALS.md\n";
