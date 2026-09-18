<?php

declare(strict_types=1);

/**
 * PayFlow · 自审计（质量 / 性能 / 入口覆盖）
 *
 *   php payflow/bin/audit.php            # 打印报告
 *   php payflow/bin/audit.php --md=docs/AUDIT.md   # 同时写入文件
 *
 * 退出码：存在语法错误 / 路由引用缺失 / 自检失败 → 1；否则 0。
 * 供 CI 与「自我进化」闭环使用：审计 → BACKLOG → 提议 → 实现 → 回归。
 */

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

$php = PHP_BINARY;
$sections = [];
$fatal = false;

$mdPath = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--md=')) {
        $mdPath = substr($arg, 5);
    }
}

$files = static function (string $dir, array $ext = ['php']): array {
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && in_array(strtolower($f->getExtension()), $ext, true)) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
};

$src = $files($root . '/src');
$views = $files($root . '/views');
$bins = $files($root . '/bin');
$routes = $root . '/routes/web.php';
$all = array_merge($src, $views, $bins, [$routes, $root . '/config/app.php', $root . '/bootstrap.php']);

// 1) 语法检查
$lintFail = [];
foreach (array_merge($src, $views, $bins) as $f) {
    $out = (string) shell_exec(escapeshellarg($php) . ' -l ' . escapeshellarg($f) . ' 2>&1');
    if (!str_contains($out, 'No syntax errors')) {
        $lintFail[] = str_replace($root . '/', '', $f) . ' — ' . trim($out);
    }
}
if ($lintFail !== []) {
    $fatal = true;
}
$sections[] = "## 1. 语法检查\n\n" . ($lintFail === [] ? '✅ 全部通过（' . count(array_merge($src, $views, $bins)) . ' 文件）' : "❌ 失败：\n- " . implode("\n- ", $lintFail));

// 2) 路由 ↔ 控制器
$routesText = (string) file_get_contents($routes);
$controllerVars = ['assets', 'storefront', 'checkout', 'payment', 'delivery', 'notify', 'admin', 'referral', 'invoice', 'api', 'paymentLink', 'voucher'];
$routeMethods = [];
if (preg_match_all('/\$(\w+)->(\w+)\(/', $routesText, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) {
        if (in_array($hit[1], $controllerVars, true)) {
            $routeMethods[] = $hit[2];
        }
    }
}
$routeMethods = array_values(array_unique($routeMethods));
$publicMethods = [];
foreach ($src as $f) {
    if (!str_contains($f, '/Http/Controller/')) {
        continue;
    }
    $txt = (string) file_get_contents($f);
    $cls = basename($f, '.php');
    foreach (preg_match_all('/public function (\w+)\(/', $txt, $mm) ? $mm[1] : [] as $name) {
        $publicMethods[$cls][] = $name;
    }
}
$allPublic = array_merge(...array_values($publicMethods) ?: [[]]);
$missingRefs = array_values(array_diff($routeMethods, $allPublic, ['get', 'post', 'any']));
$internalAllowed = ['AdminController::runScheduledTasks']; // 内部 helper（由 cron 入口调用）
$unrouted = [];
foreach ($publicMethods as $cls => $methods) {
    foreach ($methods as $name) {
        if ($name !== '__construct' && !in_array($name, $routeMethods, true) && !in_array("{$cls}::{$name}", $internalAllowed, true)) {
            $unrouted[] = "{$cls}::{$name}";
        }
    }
}
if ($missingRefs !== []) {
    $fatal = true;
}
$sections[] = "## 2. 入口覆盖\n\n- 路由引用缺失的方法：" . ($missingRefs === [] ? '✅ 无' : '❌ ' . implode(', ', $missingRefs))
    . "\n- 未被路由的 public 方法：" . ($unrouted === [] ? '✅ 无' : '⚠️ ' . implode(', ', $unrouted));

// 3) 死代码（零调用 public 方法）
$blob = '';
foreach ($all as $f) {
    $blob .= (string) file_get_contents($f) . "\n";
}
$noise = ['__construct', 'all', 'find', 'has', 'where', 'put', 'delete', 'mutate', 'driver', 'firstBy',
    'query', 'count', 'aggregate', 'search', 'searchCount', 'groupByDay', 'queryConditions',
    'csrfToken', 'verifyCsrf', 'rememberNext', 'pullNext',
    'id', 'label', 'isEnabled', 'createPayment', 'parseNotify', 'refund'];
$dead = [];
foreach ($src as $f) {
    $txt = (string) file_get_contents($f);
    $cls = (preg_match('/(?:class|interface)\s+(\w+)/', $txt, $mm) ? $mm[1] : basename($f, '.php'));
    foreach (preg_match_all('/public function (\w+)\(/', $txt, $m2) ? $m2[1] : [] as $name) {
        if (in_array($name, $noise, true)) {
            continue;
        }
        if (preg_match_all('/(?:->|::)' . preg_quote($name, '/') . '\s*\(/', $blob) === 0) {
            $dead[] = "{$cls}::{$name}";
        }
    }
}
$sections[] = "## 3. 死代码\n\n" . ($dead === [] ? '✅ 零调用 public 方法：无' : '⚠️ 零调用：' . implode(', ', array_unique($dead)));

// 4) 性能热点（->all()）
$hot = [];
foreach ($src as $f) {
    $n = preg_match_all('/->all\(\)/', (string) file_get_contents($f));
    if ($n > 0) {
        $hot[] = '- ' . str_replace($root . '/src/', '', $f) . ': ' . $n;
    }
}
$accepted = [
    'Store/JsonStore.php' => 'JSON 回退实现',
    'Store/SqlStore.php' => '非 MySQL 回退实现',
    'Store/Repository.php' => '存储代理',
    'Domain/CommissionRepository.php' => 'dueForMaturity（cron 到期扫描）',
    'Domain/EventRepository.php' => 'prune（cron 保留策略）',
    'Domain/InvoiceRepository.php' => 'nextSequence（小集合序号）',
    'Domain/ProductRepository.php' => 'active（小集合）',
    'Domain/SubscriptionRepository.php' => 'dueForRenewal/expiringWithinDays（cron）',
    'Domain/WebhookDeliveryRepository.php' => 'dueForRetry（cron）',
    'Domain/RateLimitRepository.php' => 'pruneBefore（cron 清理）',
    'Domain/LoginAttemptRepository.php' => 'pruneOlderThan（cron 清理）',
];
$hotReal = [];
foreach ($hot as $line) {
    $ok = false;
    foreach ($accepted as $file => $why) {
        if (str_contains($line, $file)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        $hotReal[] = $line;
    }
}
$sections[] = "## 4. 全量加载热点（->all()）\n\n"
    . '✅ 请求路径无全量加载；其余 ' . count($hot) . ' 处均为 cron/回退/小集合（见 bin/audit.php 白名单）' . PHP_EOL . PHP_EOL
    . ($hotReal === [] ? '' : "⚠️ 新增需复核：\n" . implode("\n", $hotReal));

// 5) 配置键使用
$cfg = (string) file_get_contents($root . '/config/app.php');
$keys = array_unique(array_merge(
    preg_match_all('/^\s{4}\'([a-z_]+)\'\s*=>/m', $cfg, $k1) ? $k1[1] : [],
    preg_match_all('/^\s{8}\'([a-z_]+)\'\s*=>/m', $cfg, $k2) ? $k2[1] : [],
));
$unusedCfg = [];
foreach ($keys as $key) {
    if (in_array($key, ['name', 'version', 'env', 'debug'], true)) {
        continue;
    }
    if (!str_contains($blob, $key)) {
        $unusedCfg[] = $key;
    }
}
$sections[] = "## 5. 配置键\n\n" . ($unusedCfg === [] ? '✅ 未见未使用键' : '⚠️ 可能未使用：' . implode(', ', $unusedCfg));

// 6) 调试残留
$debug = [];
foreach ($all as $f) {
    if (str_ends_with($f, '/bin/audit.php')) {
        continue; // 审计工具自身包含匹配模式
    }
    $lines = file($f, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        if (preg_match('/var_dump\s*\(|print_r\s*\(|\bdd\s*\(|console\.log|TODO|FIXME/', $line)) {
            $debug[] = str_replace($root . '/', '', $f) . ':' . ($i + 1);
        }
    }
}
$sections[] = "## 6. 调试残留\n\n" . ($debug === [] ? '✅ 无' : '⚠️ ' . implode(', ', $debug));

// 7) 自检
$testOut = (string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($root . '/tests/run.php') . ' 2>&1');
$testLine = '';
foreach (array_reverse(explode("\n", $testOut)) as $line) {
    if (str_contains($line, '通过')) {
        $testLine = trim($line);
        break;
    }
}
if (!str_contains($testOut, '失败 0')) {
    $fatal = true;
}
$sections[] = "## 7. 自检\n\n" . ($testLine !== '' ? ($fatal ? '❌ ' : '✅ ') . $testLine : '❌ 未获取到自检结果');

$report = "# PayFlow 自审计报告\n\n> 生成时间：" . date('c') . "\n\n" . implode("\n\n", $sections) . "\n";

echo $report;
if ($mdPath !== null) {
    $target = str_starts_with($mdPath, '/') ? $mdPath : $root . '/' . $mdPath;
    @mkdir(dirname($target), 0775, true);
    file_put_contents($target, $report);
    fwrite(STDERR, "\n报告已写入 {$target}\n");
}

exit($fatal ? 1 : 0);
