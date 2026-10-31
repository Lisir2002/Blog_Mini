<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class KernelFunctionalTest extends KernelTestCase
{
    public function testHomeRouteRendersThemeHtml(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/');
        $response = $this->kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('<!doctype html>', $body);
        self::assertStringContainsString('/assets/paper.css', $body);
    }

    public function testHealthRouteReportsDriver(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/api/v1/health');
        $data = json_decode((string) $this->kernel->handle($request)->getBody(), true);
        self::assertSame('sqlite', $data['db']);
    }

    public function testUnknownRouteIsJson404(): void
    {
        $response = $this->kernel->handle((new Psr17Factory())->createServerRequest('GET', '/api/v1/nope'));
        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('not_found', $data['error']['code']);
    }

    public function testUnknownPageRouteIsThemeHtml404(): void
    {
        $response = $this->kernel->handle((new Psr17Factory())->createServerRequest('GET', '/nope'));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('页面不存在', (string) $response->getBody());
    }

    public function testMethodNotAllowedHasAllowHeader(): void
    {
        $response = $this->kernel->handle((new Psr17Factory())->createServerRequest('DELETE', '/'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }

    public function testHandlerExceptionBecomes500WithoutLeaking(): void
    {
        $kernel = \PenBlog\Kernel\Kernel::fromConfig([
            'app' => ['debug' => false],
            'db' => ['driver' => 'sqlite', 'path' => ':memory:'],
            'paths' => ['logs' => sys_get_temp_dir() . '/penblog-test.log'],
        ]);
        $kernel->router->get('/boom', static fn () => throw new \RuntimeException('secret detail'));
        $response = $kernel->handle((new Psr17Factory())->createServerRequest('GET', '/boom'));

        self::assertSame(500, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('secret detail', $body);
    }

    public function testBootWithoutConfigEntersInstallingMode(): void
    {
        $kernel = \PenBlog\Kernel\Kernel::boot('/definitely/not/here.php');
        self::assertTrue($kernel->isInstalling());

        $factory = new Psr17Factory();
        // 其余路径 302 /install
        $response = $kernel->handle($factory->createServerRequest('GET', '/'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/install', $response->getHeaderLine('Location'));
        // /install 自检占位页
        $response = $kernel->handle($factory->createServerRequest('GET', '/install'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        // API 503 not_installed
        $response = $kernel->handle($factory->createServerRequest('GET', '/api/v1/health'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('not_installed', json_decode((string) $response->getBody(), true)['error']['code']);
    }
}
