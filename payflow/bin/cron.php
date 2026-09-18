<?php

declare(strict_types=1);

/**
 * PayFlow 定时任务（生产建议每 10–15 分钟或每小时跑一次）。
 *
 *   php payflow/bin/cron.php
 *
 * 覆盖：订阅到期提醒、自动续费/失败重试/宽限期降级、佣金解冻、（后续）Webhook 重试。
 * crontab 示例：每 15 分钟执行一次 bin/cron.php（PHP 绝对路径按服务器实际填写）。
 */

$config = require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Asia/Shanghai'));

$app = new PayFlow\Application($config);

$started = microtime(true);
echo '[' . date('c') . "] PayFlow cron start\n";

$report = [
    'subscription_reminders' => $app->subscriptionService->remindExpiring(),
    'subscription_due' => $app->subscriptionService->renewDue(),
    'commissions_matured' => $app->commissionService->mature(),
    'webhooks_retried' => $app->webhooks->retryDue(),
    'rate_limits_pruned' => $app->rateLimiter->prune(),
    'events_pruned' => $app->events->prune(
        (int) \PayFlow\Support\Arr::get($config, 'maintenance.events_retention_days', 180),
        (int) \PayFlow\Support\Arr::get($config, 'maintenance.events_max_rows', 50000),
    ),
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
printf("[%s] done in %.0fms\n", date('c'), (microtime(true) - $started) * 1000);
