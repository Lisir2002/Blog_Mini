<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PenBlog\Kernel\NotInstalledException;
use PHPUnit\Framework\TestCase;

final class KernelFunctionalTest extends KernelTestCase
{
    public function testHomeRouteReturnsJson(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/');
        $response = $this->kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('PenBlog Engine', $data['name']);
    }

    public function testHealthRouteReportsDriver(): void
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/api/v1/health');
        $data = json_decode((string) $this->kernel->handle($request)->getBody(), true);
        self::assertSame('sqlite', $data['db']);
    }

    public function testUnknownRouteIsJson404(): void
    {
        $response = $this->kernel->handle((new Psr17Factory())->createServerRequest('GET', '/nope'));
        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('not_found', $data['error']['code']);
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

    public function testBootWithoutConfigThrowsNotInstalled(): void
    {
        $this->expectException(NotInstalledException::class);
        \PenBlog\Kernel\Kernel::boot('/definitely/not/here.php');
    }
}
