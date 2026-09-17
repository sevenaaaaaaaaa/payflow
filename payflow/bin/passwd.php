<?php

declare(strict_types=1);

/**
 * 生成后台密码哈希，写入 data/config.json 的 admin.password_hash。
 *
 *   php payflow/bin/passwd.php '你的新密码'            # 只打印哈希
 *   php payflow/bin/passwd.php '你的新密码' --save     # 同时写入 data/config.json
 */

$password = $argv[1] ?? '';
if ($password === '') {
    fwrite(STDERR, "用法: php bin/passwd.php '新密码' [--save]\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
echo "password_hash: {$hash}\n";

if (in_array('--save', $argv, true)) {
    $file = dirname(__DIR__) . '/data/config.json';
    $config = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $config['admin']['username'] = $config['admin']['username'] ?? 'admin';
    $config['admin']['password_hash'] = $hash;
    unset($config['admin']['password']);
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "已写入 {$file}（admin.username={$config['admin']['username']}）\n";
}
