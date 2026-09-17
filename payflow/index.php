<?php

declare(strict_types=1);

/**
 * PayFlow · 前端控制器
 *
 * Apache vhost 根目录指向本文件所在目录（/www/wwwroot/payflow）。
 * 本地开发：php -S 127.0.0.1:8787 -t payflow payflow/index.php
 */

$config = require __DIR__ . '/bootstrap.php';

date_default_timezone_set((string) ($config['app']['timezone'] ?? 'Asia/Shanghai'));

if (!empty($config['app']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

$app = new PayFlow\Application($config);

$router = new PayFlow\Http\Router(
    (bool) ($config['app']['debug'] ?? false),
    (string) ($config['app']['base_path'] ?? ''),
);

(require __DIR__ . '/routes/web.php')($router, $app);

$request = PayFlow\Http\Request::fromGlobals();
$router->dispatch($request)->send();
