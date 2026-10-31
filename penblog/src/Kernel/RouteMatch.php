<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

final class RouteMatch
{
    /**
     * @param list<object>  $middleware
     * @param array<string,string> $params
     * @param list<string>  $allowedMethods
     */
    private function __construct(
        public readonly ?\Closure $handler,
        public readonly array $middleware,
        public readonly array $params,
        public readonly ?array $allowedMethods,
    ) {
    }

    public static function matched(\Closure $handler, array $middleware, array $params): self
    {
        return new self($handler, $middleware, $params, null);
    }

    public static function methodNotAllowed(array $allowed): self
    {
        return new self(null, [], [], $allowed);
    }

    public function isMethodNotAllowed(): bool
    {
        return $this->handler === null;
    }
}
