<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

/**
 * 路由:pattern 中 {param} 捕获为段;路由级中间件随匹配返回(见 02 §3)。
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, params:list<string>, handler:callable, middleware:list<object>}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => \Closure::fromCallable($handler),
            'middleware' => $middleware,
        ];
    }

    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function put(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    public function delete(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    /** 注册表快照(06 §7 OpenAPI 导出用):不含 handler。 @return list<array{method:string, pattern:string}> */
    public function routes(): array
    {
        return array_map(
            static fn (array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern']],
            $this->routes,
        );
    }

    public function match(string $method, string $path): ?RouteMatch
    {
        $method = strtoupper($method);
        $pathMatched = false;
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            array_shift($matches);
            return RouteMatch::matched(
                handler: $route['handler'],
                middleware: $route['middleware'],
                params: array_combine($route['params'], array_map(rawurldecode(...), $matches)),
            );
        }
        if ($pathMatched) {
            return RouteMatch::methodNotAllowed(array_values(array_unique($allowed)));
        }
        return null;
    }
}
