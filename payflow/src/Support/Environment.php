<?php

declare(strict_types=1);

namespace PayFlow\Support;

/**
 * Runtime environment (development vs production) and the outbound side-effect guard.
 *
 * Resolution order:
 *   1) PAYFLOW_ENV, then APP_ENV (explicit env var always wins)
 *   2) OS default: macOS (Darwin) => development, everything else (Linux server) => production
 *
 * In development every outbound side effect (payment gateways, merchant webhooks, email,
 * AI calls) is blocked unless the target is a loopback host or PAYFLOW_ALLOW_LIVE=1 is set.
 * Production is never guarded, so server behaviour is unchanged.
 */
final class Environment
{
    public const PRODUCTION = 'production';
    public const DEVELOPMENT = 'development';

    private const PRODUCTION_ALIASES = ['production', 'prod', 'live'];

    private static ?string $forcedEnv = null;
    private static ?bool $forcedAllowLive = null;
    private static ?string $forcedOsFamily = null;

    private static int $blocked = 0;

    /** @var (callable(string):void)|null */
    private static $logger = null;

    public static function name(): string
    {
        if (self::$forcedEnv !== null) {
            return self::$forcedEnv;
        }

        return self::resolve(
            self::readVar('PAYFLOW_ENV') ?? self::readVar('APP_ENV'),
            self::$forcedOsFamily ?? PHP_OS_FAMILY,
        );
    }

    /**
     * Pure resolution rule (exposed for tests). Unknown explicit values fall back to
     * development, which is the safe side.
     */
    public static function resolve(?string $explicit, string $osFamily): string
    {
        $explicit = strtolower(trim((string) $explicit));
        if ($explicit !== '') {
            return in_array($explicit, self::PRODUCTION_ALIASES, true) ? self::PRODUCTION : self::DEVELOPMENT;
        }

        return $osFamily === 'Darwin' ? self::DEVELOPMENT : self::PRODUCTION;
    }

    public static function isProduction(): bool
    {
        return self::name() === self::PRODUCTION;
    }

    /** Whether PAYFLOW_ALLOW_LIVE=1 was set (deliberate live call from a dev machine). */
    public static function allowLive(): bool
    {
        if (self::$forcedAllowLive !== null) {
            return self::$forcedAllowLive;
        }
        $value = strtolower((string) self::readVar('PAYFLOW_ALLOW_LIVE'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** True when real outbound side effects are permitted. */
    public static function liveAllowed(): bool
    {
        return self::isProduction() || self::allowLive();
    }

    /**
     * Central decision for one outbound target. Loopback targets (local mock servers,
     * Mailpit, sibling apps on localhost) are always allowed.
     */
    public static function permitsOutbound(string $target): bool
    {
        return self::liveAllowed() || self::isLoopback($target);
    }

    /**
     * Throws when the outbound call is not permitted; logs a clearly-labelled dry-run line.
     *
     * @throws OutboundBlockedException
     */
    public static function guardOutbound(string $kind, string $target): void
    {
        if (self::permitsOutbound($target)) {
            return;
        }
        $message = sprintf(
            '[PayFlow][DEV-DRY-RUN] %s to %s blocked: env=%s, no real side effect happened (set PAYFLOW_ALLOW_LIVE=1 to call live)',
            $kind,
            self::redact($target),
            self::name(),
        );
        self::$blocked++;
        self::log($message);

        throw new OutboundBlockedException($message);
    }

    /** Number of outbound calls blocked in this process (lets callers label dry-run results). */
    public static function blockedCount(): int
    {
        return self::$blocked;
    }

    public static function isLoopback(string $target): bool
    {
        $host = parse_url($target, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            // Bare host or host:port (e.g. SMTP host)
            $host = preg_replace('/:\d+$/', '', $target) ?? $target;
        }
        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '::1'
            || str_starts_with($host, '127.');
    }

    public static function log(string $message): void
    {
        if (self::$logger !== null) {
            (self::$logger)($message);

            return;
        }
        error_log($message);
    }

    /**
     * Test hook: force env / allow-live / OS family and capture log lines. Pass nulls to reset.
     *
     * @param (callable(string):void)|null $logger
     */
    public static function override(?string $env = null, ?bool $allowLive = null, ?string $osFamily = null, ?callable $logger = null): void
    {
        self::$forcedEnv = $env;
        self::$forcedAllowLive = $allowLive;
        self::$forcedOsFamily = $osFamily;
        self::$logger = $logger;
    }

    private static function readVar(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Strip query strings / credentials from URLs before logging. */
    private static function redact(string $target): string
    {
        $parts = parse_url($target);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $target;
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '');
    }
}
