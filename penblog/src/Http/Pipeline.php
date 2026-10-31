<?php

declare(strict_types=1);

namespace PenBlog\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 中间件管线:global 中间件 + 路由中间件 + 终点 handler。
 */
final class Pipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $queue;

    /**
     * @param list<MiddlewareInterface> $middleware
     * @param callable(ServerRequestInterface): ResponseInterface $destination
     */
    public function __construct(array $middleware, private $destination)
    {
        $this->queue = array_values($middleware);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middleware = array_shift($this->queue);
        if ($middleware === null) {
            return ($this->destination)($request);
        }
        return $middleware->process($request, $this);
    }
}
