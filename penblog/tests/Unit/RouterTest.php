<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit;

use PenBlog\Kernel\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesRouteWithParams(): void
    {
        $router = new Router();
        $router->get('/api/v1/posts/{slug}', static fn () => null);
        $match = $router->match('GET', '/api/v1/posts/hello-world');

        self::assertNotNull($match);
        self::assertFalse($match->isMethodNotAllowed());
        self::assertSame(['slug' => 'hello-world'], $match->params);
    }

    public function testDecodesParamValues(): void
    {
        $router = new Router();
        $router->get('/t/{name}', static fn () => null);
        $match = $router->match('GET', '/t/' . rawurlencode('中文标签'));
        self::assertSame(['name' => '中文标签'], $match->params);
    }

    public function testUnknownPathReturnsNull(): void
    {
        $router = new Router();
        $router->get('/', static fn () => null);
        self::assertNull($router->match('GET', '/nope'));
    }

    public function testMethodNotAllowedCarriesAllowedList(): void
    {
        $router = new Router();
        $router->get('/posts', static fn () => null);
        $router->post('/posts', static fn () => null);

        $match = $router->match('DELETE', '/posts');
        self::assertNotNull($match);
        self::assertTrue($match->isMethodNotAllowed());
        self::assertSame(['GET', 'POST'], $match->allowedMethods);
    }

    public function testStaticPatternDoesNotMatchSubpath(): void
    {
        $router = new Router();
        $router->get('/posts', static fn () => null);
        self::assertNull($router->match('GET', '/posts/1'));
    }
}
