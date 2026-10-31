<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

final class MenuApiTest extends KernelTestCase
{
    public function testAnonymousIsForbidden(): void
    {
        $response = $this->kernel->handle($this->request('GET', '/api/v1/menus'));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testPutGetDeleteRoundTrip(): void
    {
        $cookies = $this->login('admin', 'password123');

        // PUT 保存
        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/menus/primary', $cookies, [
            'items' => [
                ['label' => '首页', 'type' => 'url', 'target' => '/', 'sort' => 1],
                ['label' => '关于', 'type' => 'page', 'target' => 'about', 'sort' => 2],
            ],
        ]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $menu = json_decode((string) $response->getBody(), true)['menu'];
        self::assertSame('primary', $menu['location']);
        self::assertSame(1, $menu['resourceVersion']);

        // GET 回读
        $response = $this->kernel->handle($this->request('GET', '/api/v1/menus/primary', null, [], $cookies));
        self::assertSame(200, $response->getStatusCode());
        $read = json_decode((string) $response->getBody(), true)['menu'];
        self::assertSame(['首页', '关于'], array_column($read['items'], 'label'));

        // 列表
        $response = $this->kernel->handle($this->request('GET', '/api/v1/menus', null, [], $cookies));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['primary'], array_column(json_decode((string) $response->getBody(), true)['items'], 'location'));

        // DELETE 后 404
        $response = $this->kernel->handle($this->authRequest('DELETE', '/api/v1/menus/primary', $cookies));
        self::assertSame(200, $response->getStatusCode());
        $response = $this->kernel->handle($this->request('GET', '/api/v1/menus/primary', null, [], $cookies));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testInvalidItemReturnsValidationError(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/menus/primary', $cookies, [
            'items' => [['label' => '坏', 'type' => 'widget', 'target' => 'x']],
        ]));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testGetMissingMenuIs404(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->request('GET', '/api/v1/menus/ghost', null, [], $cookies));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testOpenApiDocumentsMenuEndpoints(): void
    {
        $response = $this->kernel->handle($this->request('GET', '/api/v1/openapi.json'));
        self::assertSame(200, $response->getStatusCode());
        $spec = json_decode((string) $response->getBody(), true);
        foreach (['/api/v1/menus' => ['get'], '/api/v1/menus/{location}' => ['get', 'put', 'delete']] as $path => $methods) {
            self::assertArrayHasKey($path, $spec['paths']);
            foreach ($methods as $m) {
                self::assertArrayHasKey($m, $spec['paths'][$path], "{$path} {$m}");
                self::assertArrayNotHasKey('x-undocumented', $spec['paths'][$path][$m]);
                self::assertSame('system:manage', $spec['paths'][$path][$m]['x-permission']);
            }
        }
    }
}
