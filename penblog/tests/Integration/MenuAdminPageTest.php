<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Content\Page;
use PenBlog\Kernel\Metadata;

final class MenuAdminPageTest extends KernelTestCase
{
    private function seedPage(string $slug, string $title): void
    {
        $this->kernel->extensions()->register(new Page(
            Metadata::generate($slug, Page::KIND),
            ['title' => $title, 'status' => 'published', 'bodyMd' => '内容'],
        ));
    }

    /** @param array<string, mixed> $form */
    private function formRequest(string $method, string $path, array $cookies, array $form): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->authRequest($method, $path, $cookies)->withParsedBody($form);
    }

    public function testAnonymousRedirectsToLogin(): void
    {
        $response = $this->kernel->handle($this->request('GET', '/admin/menus'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testAdminPageRenders(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->request('GET', '/admin/menus', null, [], $cookies));
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('菜单', $html);
        self::assertStringContainsString('新建菜单', $html);
        self::assertStringContainsString('/admin/menus', $html, '侧栏入口');
    }

    public function testFormSaveAppearsInFrontNavAndDeleteRestoresDefault(): void
    {
        $this->seedPage('about', '关于');
        $cookies = $this->login('admin', 'password123');

        $response = $this->kernel->handle($this->formRequest('POST', '/admin/menus/primary', $cookies, [
            '_csrf' => $cookies[\PenBlog\Auth\SessionStore::CSRF_COOKIE],
            'items' => [
                0 => ['label' => '首页', 'type' => 'url', 'target' => '/', 'sort' => '1'],
                1 => ['label' => '关于', 'type' => 'page', 'target' => 'about', 'sort' => '2'],
                2 => ['label' => '', 'type' => 'url', 'target' => '', 'sort' => '0'], // 空行忽略
            ],
        ]));
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('ok=primary', $response->getHeaderLine('Location'));

        // 前台首页导航出现新链接,空行未入库
        $response = $this->kernel->handle($this->request('GET', '/'));
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('href="/p/about"', $html);
        self::assertStringContainsString('关于', $html);
        self::assertStringNotContainsString('href=""', $html);

        // 编辑视图回显
        $response = $this->kernel->handle($this->request('GET', '/admin/menus?edit=primary', null, [], $cookies));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('编辑菜单:primary', (string) $response->getBody());

        // 删除后回默认"首页"
        $response = $this->kernel->handle($this->formRequest('POST', '/admin/menus/primary/delete', $cookies, [
            '_csrf' => $cookies[\PenBlog\Auth\SessionStore::CSRF_COOKIE],
        ]));
        self::assertSame(302, $response->getStatusCode());
        $response = $this->kernel->handle($this->request('GET', '/'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('>首页</a>', $html);
        self::assertStringNotContainsString('href="/p/about"', $html);
    }

    public function testNewMenuViaFormLocationField(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->formRequest('POST', '/admin/menus', $cookies, [
            '_csrf' => $cookies[\PenBlog\Auth\SessionStore::CSRF_COOKIE],
            'location' => 'footer',
            'items' => [
                0 => ['label' => 'RSS', 'type' => 'url', 'target' => '/feed', 'sort' => '0'],
            ],
        ]));
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('ok=footer', $response->getHeaderLine('Location'));

        $response = $this->kernel->handle($this->request('GET', '/'));
        self::assertStringContainsString('页脚导航', (string) $response->getBody());
        self::assertStringContainsString('href="/feed"', (string) $response->getBody());
    }

    public function testInvalidSaveRedirectsWithError(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->formRequest('POST', '/admin/menus/primary', $cookies, [
            '_csrf' => $cookies[\PenBlog\Auth\SessionStore::CSRF_COOKIE],
            'items' => [
                0 => ['label' => '坏', 'type' => 'widget', 'target' => 'x', 'sort' => '0'],
            ],
        ]));
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('error=validation_failed', $response->getHeaderLine('Location'));
    }
}
