<?php

declare(strict_types=1);

namespace PayFlow\Http;

use PayFlow\Support\Arr;

/**
 * 后台登录：用户名 + 密码，PHP 原生会话。
 *
 * 配置（data/config.json）：
 *   admin.username      登录名
 *   admin.password_hash password_hash() 结果（推荐）
 *   admin.password      明文密码（可选，本地开发方便）
 *   admin.session_key   会话名
 */
final class AdminAuth
{
    public function __construct(private readonly array $config)
    {
    }

    private function username(): string
    {
        return (string) Arr::get($this->config, 'admin.username', 'admin');
    }

    private function passwordHash(): string
    {
        return (string) Arr::get($this->config, 'admin.password_hash', '');
    }

    private function passwordPlain(): string
    {
        return (string) Arr::get($this->config, 'admin.password', '');
    }

    public function configured(): bool
    {
        return $this->passwordHash() !== '' || $this->passwordPlain() !== '';
    }

    public function sessionName(): string
    {
        return (string) Arr::get($this->config, 'admin.session_key', 'pf_admin');
    }

    public function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        session_name($this->sessionName());
        session_set_cookie_params([
            'path' => pf_base_path() !== '' ? pf_base_path() . '/' : '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
        ]);
        session_start();
    }

    public function attempt(Request $request): bool
    {
        $this->bootSession();
        if (($_SESSION['pf_admin_ok'] ?? false) !== true) {
            return false;
        }
        $now = time();
        $loginAt = (int) ($_SESSION['pf_admin_login_at'] ?? $now);
        $last = (int) ($_SESSION['pf_admin_last'] ?? $now);
        $idle = max(1, (int) Arr::get($this->config, 'admin.session_idle_minutes', 480)) * 60;
        $absolute = max(1, (int) Arr::get($this->config, 'admin.session_days', 7)) * 86400;
        if ($now - $last > $idle || $now - $loginAt > $absolute) {
            $this->destroySession();

            return false;
        }
        $_SESSION['pf_admin_last'] = $now;

        return true;
    }

    /** 记录未登录访问的后台路径，登录成功后回跳。 */
    public function rememberNext(string $path): void
    {
        $this->bootSession();
        if ($this->safePath($path)) {
            $_SESSION['pf_next'] = $path;
        }
    }

    public function pullNext(): ?string
    {
        $this->bootSession();
        $path = $_SESSION['pf_next'] ?? null;
        unset($_SESSION['pf_next']);

        return is_string($path) && $this->safePath($path) ? $path : null;
    }

    private function safePath(string $path): bool
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }
        $base = pf_base_path();
        if ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/')) {
            return false;
        }

        return !str_starts_with($path, pf_url('/admin/login'));
    }

    private function destroySession(): void
    {
        $_SESSION = [];
        if (!headers_sent() && ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => pf_base_path() !== '' ? pf_base_path() . '/' : '/',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function login(Request $request): bool
    {
        $user = (string) $request->string('username');
        $pass = (string) $request->string('password');
        if (!$this->verify($user, $pass)) {
            return false;
        }

        $this->bootSession();
        session_regenerate_id(true);
        $_SESSION['pf_admin_ok'] = true;
        $_SESSION['pf_admin_user'] = $user;
        $_SESSION['pf_admin_at'] = time();
        $_SESSION['pf_admin_login_at'] = time();
        $_SESSION['pf_admin_last'] = time();
        $_SESSION['pf_csrf'] = bin2hex(random_bytes(16));

        return true;
    }

    public function csrfToken(): string
    {
        $this->bootSession();
        if (empty($_SESSION['pf_csrf'])) {
            $_SESSION['pf_csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['pf_csrf'];
    }

    public function verifyCsrf(Request $request): bool
    {
        $this->bootSession();
        $token = (string) ($request->input('_csrf', '') ?: $request->header('X-CSRF-Token', '') ?? '');

        return $token !== '' && hash_equals((string) ($_SESSION['pf_csrf'] ?? ''), $token);
    }


    public function logout(): void
    {
        $this->bootSession();
        $this->destroySession();
    }

    private function verify(string $user, string $pass): bool
    {
        if ($user === '' || $pass === '' || !hash_equals($this->username(), $user)) {
            return false;
        }
        $hash = $this->passwordHash();
        if ($hash !== '') {
            return password_verify($pass, $hash);
        }
        $plain = $this->passwordPlain();

        return $plain !== '' && hash_equals($plain, $pass);
    }
}
