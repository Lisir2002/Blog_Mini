<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\UserRepository;

/**
 * 04 §8 插件管理 API(system:manage)+ OpenAPI 动态端点登记。
 * 使用项目 plugins/hello-world 示例插件。
 */
final class PluginApiTest extends KernelTestCase
{
    public function testPluginsApiRequiresSystemManage(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/api/v1/plugins'));
        self::assertSame(403, $res->getStatusCode(), '匿名无 system:manage');

        $this->kernel->container->get(UserRepository::class)->create('lurker', 'lurker@example.com', 'password123', 3);
        $cookies = $this->login('lurker', 'password123');
        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/plugins', $cookies));
        self::assertSame(403, $res->getStatusCode(), 'L3 无 system:manage');
    }

    public function testFullLifecycleViaApi(): void
    {
        $cookies = $this->login('admin', 'password123');

        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/plugins', $cookies));
        self::assertSame(200, $res->getStatusCode());
        $items = json_decode((string) $res->getBody(), true)['items'];
        $hello = self::findPlugin($items, 'hello-world');
        self::assertSame('none', $hello['state']);
        self::assertContains('router.register', $hello['permissions']);

        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/plugins/hello-world:install', $cookies));
        self::assertSame(201, $res->getStatusCode());
        self::assertSame('installed', json_decode((string) $res->getBody(), true)['plugin']['state']);

        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/plugins/hello-world:activate', $cookies));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('active', json_decode((string) $res->getBody(), true)['plugin']['state']);

        // 插件路由生效
        $res = $this->kernel->handle($this->request('GET', '/hello'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('Hello from PenBlog!', (string) $res->getBody());

        // 插件 API 端点 + OpenAPI 动态登记(04 §4 APIEndpoint)
        $res = $this->kernel->handle($this->request('GET', '/api/v1/hello/status'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('hello-world', json_decode((string) $res->getBody(), true)['plugin']);

        $spec = json_decode((string) $this->kernel->handle($this->request('GET', '/api/v1/openapi.json'))->getBody(), true);
        $op = $spec['paths']['/api/v1/hello/status']['get'];
        self::assertSame('hello-world', $op['x-plugin']);
        self::assertSame('Hello 插件状态', $op['summary']);
        self::assertArrayNotHasKey('x-undocumented', $op);
        self::assertSame([], $op['security'], 'rbac 为空的插件端点应公开');

        // SettingSection 保存 → 路由读取新值(命名空间隔离)
        $this->kernel->plugins()->saveSettings('hello-world', 'hello-greeting', ['greeting' => 'Bonjour']);
        $res = $this->kernel->handle($this->request('GET', '/hello'));
        self::assertStringContainsString('Bonjour', (string) $res->getBody());
        self::assertSame('Bonjour', $this->kernel->options()->get('plugin.hello-world.settings.hello-greeting.greeting'));

        // 停用:注册撤销
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/plugins/hello-world:deactivate', $cookies));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(404, $this->kernel->handle($this->request('GET', '/hello'))->getStatusCode());
        $spec = json_decode((string) $this->kernel->handle($this->request('GET', '/api/v1/openapi.json'))->getBody(), true);
        self::assertArrayNotHasKey('/api/v1/hello/status', $spec['paths'], '停用后动态端点应移出文档');

        // 卸载:数据清理
        $res = $this->kernel->handle($this->authRequest('DELETE', '/api/v1/plugins/hello-world', $cookies));
        self::assertSame(200, $res->getStatusCode());
        self::assertNull($this->kernel->options()->get('plugin.hello-world.activated_at'));
        self::assertNull($this->kernel->options()->get('plugin.hello-world.settings.hello-greeting.greeting'));
        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/plugins', $cookies));
        self::assertSame('none', self::findPlugin(json_decode((string) $res->getBody(), true)['items'], 'hello-world')['state']);
    }

    public function testPluginAssetsRouteServesWhitelistOnly(): void
    {
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/plugin-assets/hello-world/hello.css'))->getStatusCode());
        self::assertSame(404, $this->kernel->handle($this->request('GET', '/plugin-assets/hello-world/plugin.json'))->getStatusCode(), 'manifest 不得经静态路由暴露');
        self::assertSame(404, $this->kernel->handle($this->request('GET', '/plugin-assets/hello-world/..%2F..%2Fconfig%2Fconfig.php'))->getStatusCode());
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private static function findPlugin(array $items, string $name): array
    {
        foreach ($items as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }
        self::fail("plugin {$name} not listed");
    }
}
