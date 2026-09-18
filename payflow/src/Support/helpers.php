<?php

declare(strict_types=1);

if (!function_exists('pf_e')) {
    /** HTML 转义输出 */
    function pf_e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('pf_json')) {
    /** 统一 JSON 响应（不依赖框架） */
    function pf_json(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('pf_csrf_token')) {
    /** 当前会话的 CSRF 令牌（无会话时返回空串） */
    function pf_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['pf_csrf'])) {
            $_SESSION['pf_csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['pf_csrf'];
    }
}

if (!function_exists('pf_base_path')) {
    /** 当前挂载子路径（默认空），如 /payflow */
    function pf_base_path(): string
    {
        return (string) ($GLOBALS['PF_BASE_PATH'] ?? '');
    }
}

if (!function_exists('pf_url')) {
    /** 生成带挂载前缀的内部绝对路径：pf_url('/admin') → /payflow/admin */
    function pf_url(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        return pf_base_path() . $path;
    }
}

if (!function_exists('pf_asset')) {
    /** 静态资产 URL：pf_asset('tokens.css') → /payflow/assets/tokens.css */
    function pf_asset(string $file): string
    {
        return pf_base_path() . '/assets/' . ltrim($file, '/');
    }
}
