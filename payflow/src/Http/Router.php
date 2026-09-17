<?php

declare(strict_types=1);

namespace PayFlow\Http;

use PayFlow\Support\View;
use Throwable;

final class Router
{
    /** @var list<array{method:string,regex:string,params:list<string>,handler:callable}> */
    private array $routes = [];

    public function __construct(private readonly bool $debug = false, private readonly string $basePath = '')
    {
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * 同时注册 GET/POST（表单页 + 提交）。
     */
    public function any(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', static function (array $m) use (&$params): string {
            $params[] = $m[1];

            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $methodMatched = false;
        $path = $this->stripBasePath($request->path);

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $methodMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            array_shift($matches);
            $bound = [];
            foreach ($route['params'] as $index => $name) {
                $bound[$name] = $matches[$index] ?? '';
            }
            $request->setRouteParams($bound);

            try {
                $result = ($route['handler'])($request);
            } catch (Throwable $e) {
                return $this->renderError($e);
            }

            if ($result instanceof Response) {
                return $result;
            }
            if (is_string($result)) {
                return Response::html($result);
            }
            if (is_array($result)) {
                return Response::json($result);
            }

            return Response::text('', 204);
        }

        return $methodMatched
            ? Response::html($this->errorPage(405, '请求方法不被支持'), 405)
            : Response::html($this->errorPage(404, '页面不存在'), 404);
    }

    /**
     * 剥离挂载子路径：/payflow/admin → /admin（未挂载时原样返回）。
     */
    private function stripBasePath(string $path): string
    {
        $base = $this->basePath;
        if ($base === '' || $base === '/') {
            return $path;
        }
        if ($path === $base) {
            return '/';
        }
        if (str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    private function renderError(Throwable $e): Response
    {
        $payload = [
            'error' => $e->getMessage(),
            'type' => $e::class,
        ];
        if ($this->debug) {
            $payload['file'] = $e->getFile() . ':' . $e->getLine();
            $payload['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 12);
        }

        error_log('[PayFlow] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        return Response::html(
            View::render('error', ['message' => $this->debug ? $e->getMessage() : '服务暂时不可用', 'payload' => $payload]),
            500,
        );
    }

    private function errorPage(int $code, string $message): string
    {
        return View::render('error', ['code' => $code, 'message' => $message, 'payload' => null]);
    }
}
