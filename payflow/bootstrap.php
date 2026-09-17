<?php

declare(strict_types=1);

/**
 * PayFlow · 独立代码库引导（PHP 8.3，无框架，零 composer 运行时依赖）
 *
 * - 自动加载 PayFlow\ 命名空间
 * - 载入 config/app.php 并与 data/config.json（服务器侧可变配置，gitignored）深合并
 * - 提供应用容器（服务定位器），供前端控制器与 CLI 共用
 */

defined('PAYFLOW_ROOT') || define('PAYFLOW_ROOT', __DIR__);
defined('PAYFLOW_START') || define('PAYFLOW_START', microtime(true));

spl_autoload_register(static function (string $class): void {
    $prefix = 'PayFlow\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = PAYFLOW_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require PAYFLOW_ROOT . '/src/Support/helpers.php';

$config = require PAYFLOW_ROOT . '/config/app.php';

$overrideFile = PAYFLOW_ROOT . '/data/config.json';
if (is_file($overrideFile)) {
    $override = json_decode((string) file_get_contents($overrideFile), true);
    if (is_array($override)) {
        $config = \PayFlow\Support\Arr::mergeDeep($config, $override);
    }
}

// 挂载子路径（如 /payflow）供 URL 生成使用；路由匹配时会自动剥离。
$GLOBALS['PF_BASE_PATH'] = rtrim((string) ($config['app']['base_path'] ?? ''), '/');

// 全站签名密钥（签名下载/API HMAC）。优先 config.secret，回退后台密码哈希。
$secret = (string) ($config['secret'] ?? '');
if ($secret === '') {
    $secret = (string) ($config['admin']['password_hash'] ?? '');
}
if ($secret === '') {
    $secret = (string) ($config['admin']['password'] ?? '');
}
$GLOBALS['PF_SECRET'] = $secret;

return $config;
