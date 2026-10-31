<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

/**
 * 04 §8 后台插件页:守卫、列表、操作按钮、MenuItem 注入侧栏、SettingSection 表单。
 */
final class PluginAdminPageTest extends KernelTestCase
{
    public function testAnonymousRedirectsToLogin(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/admin/plugins'));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/admin/login', $res->getHeaderLine('Location'));
    }

    public function testAdminPageListsPluginsAndRunsActions(): void
    {
        $cookies = $this->login('admin', 'password123');

        $res = $this->kernel->handle($this->authRequest('GET', '/admin/plugins', $cookies));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        self::assertStringContainsString('hello-world', $html);
        self::assertStringContainsString('/admin/plugins/hello-world/install', $html);

        // 安装 + 启用
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/plugins/hello-world/install', $cookies, ['_csrf' => $cookies['penblog_csrf']]));
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('ok=', $res->getHeaderLine('Location'));
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/plugins/hello-world/activate', $cookies));
        self::assertSame(302, $res->getStatusCode());

        // 插件路由生效 + MenuItem 注入后台侧栏
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/hello'))->getStatusCode());
        $html = (string) $this->kernel->handle($this->authRequest('GET', '/admin/dashboard', $cookies))->getBody();
        self::assertStringContainsString('Hello 页', $html);

        // SettingSection 表单保存
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/plugins/hello-world/settings/hello-greeting', $cookies, ['greeting' => '你好,PenBlog']));
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('你好,PenBlog', (string) $this->kernel->handle($this->request('GET', '/hello'))->getBody());

        // 停用按钮存在,失败操作经 error 回跳
        $html = (string) $this->kernel->handle($this->authRequest('GET', '/admin/plugins', $cookies))->getBody();
        self::assertStringContainsString('/admin/plugins/hello-world/deactivate', $html);
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/plugins/hello-world/activate', $cookies));
        self::assertStringContainsString('ok=', $res->getHeaderLine('Location'), '重复激活应幂等成功');
    }

    public function testFailedPluginShowsErrorSummary(): void
    {
        $manager = new \PenBlog\Plugin\PluginManager($this->kernel, dirname(__DIR__) . '/fixtures/plugins');
        $this->kernel->container->instance(\PenBlog\Plugin\PluginManager::class, $manager);
        $manager->install('broken');
        try {
            $manager->activate('broken');
        } catch (\PenBlog\Api\ApiException) {
        }
        $cookies = $this->login('admin', 'password123');
        $html = (string) $this->kernel->handle($this->authRequest('GET', '/admin/plugins', $cookies))->getBody();
        self::assertStringContainsString('failed', $html);
        self::assertStringContainsString('boom', $html);
        self::assertStringContainsString('重试激活', $html);
    }
}
