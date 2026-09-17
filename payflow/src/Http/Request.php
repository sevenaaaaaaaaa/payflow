<?php

declare(strict_types=1);

namespace PayFlow\Http;

final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];
    private ?array $jsonCache = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $headers,
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $raw = (string) file_get_contents('php://input');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self($method, $path, $_GET, $_POST, $headers, $raw);
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        $value = $this->routeParams[$key] ?? $default;

        return $value === null ? null : (string) $value;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }

        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', '') ?? '', 'application/json');
    }

    public function json(): array
    {
        if ($this->jsonCache === null) {
            $decoded = json_decode($this->rawBody, true);
            $this->jsonCache = is_array($decoded) ? $decoded : [];
        }

        return $this->jsonCache;
    }

    /**
     * 表单或 JSON 正文统一取值。
     */
    public function payload(): array
    {
        return $this->isJson() ? array_merge($this->query, $this->json()) : array_merge($this->query, $this->post);
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
