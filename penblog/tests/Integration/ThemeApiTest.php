<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

/**
 * 主题管理 API(03 §7,system:manage)+ OpenAPI 登记。
 */
final class ThemeApiTest extends KernelTestCase
{
    public function testThemesApiRequiresSystemManage(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/api/v1/themes'));
        self::assertSame(403, $res->getStatusCode(), '匿名无 system:manage');

        // 会话写操作缺 CSRF 头 → 拒绝
        $cookies = $this->login('admin', 'password123');
        $res = $this->kernel->handle($this->request('POST', '/api/v1/themes/ink:activate', null, [], $cookies));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testListActivateSettingsAndUninstall(): void
    {
        $cookies = $this->login('admin', 'password123');

        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/themes', $cookies));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        self::assertSame('paper', $body['active']);
        self::assertSame([], $body['errors']);
        $ink = self::findTheme($body['items'], 'ink');
        self::assertSame('inactive', $ink['state']);
        self::assertSame('paper', $ink['extends']);
        self::assertTrue($ink['hasSettings']);

        // 激活
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/themes/ink:activate', $cookies));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('active', json_decode((string) $res->getBody(), true)['theme']['state']);
        self::assertSame('ink', $this->kernel->options()->get('active_theme'));

        // 激活中的主题不可停用
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/themes/ink:deactivate', $cookies));
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('theme_active', json_decode((string) $res->getBody(), true)['error']['code']);

        // 设置:schema + 默认值 → 保存(钳制)→ 回读
        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/themes/ink/settings', $cookies));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        self::assertCount(4, $body['schema']);
        self::assertSame(10, $body['values']['post_per_page']);

        $res = $this->kernel->handle($this->authRequest('PUT', '/api/v1/themes/ink/settings', $cookies, [
            'post_per_page' => 999,
            'layout' => 'list',
        ]));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(50, json_decode((string) $res->getBody(), true)['values']['post_per_page']);

        // 非法 radio 值 → 409
        $res = $this->kernel->handle($this->authRequest('PUT', '/api/v1/themes/ink/settings', $cookies, ['layout' => 'masonry']));
        self::assertSame(409, $res->getStatusCode());

        // 切回 paper 后卸载 ink:命名空间清理,文件保留
        $this->kernel->theme()->activate('paper');
        $res = $this->kernel->handle($this->authRequest('DELETE', '/api/v1/themes/ink', $cookies));
        self::assertSame(200, $res->getStatusCode());
        self::assertNull($this->kernel->options()->get('theme.ink.layout'));

        // 不存在主题 → 404
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/themes/ghost:activate', $cookies));
        self::assertSame(404, $res->getStatusCode());
    }

    public function testThemeEndpointsAreDocumentedInOpenApi(): void
    {
        $spec = json_decode((string) $this->kernel->handle($this->request('GET', '/api/v1/openapi.json'))->getBody(), true);
        foreach ([
            'get /api/v1/themes',
            'post /api/v1/themes/{name}:activate',
            'delete /api/v1/themes/{name}',
            'get /api/v1/themes/{name}/settings',
            'put /api/v1/themes/{name}/settings',
        ] as $entry) {
            [$method, $path] = explode(' ', $entry);
            $op = $spec['paths'][$path][$method] ?? null;
            self::assertNotNull($op, "missing {$entry}");
            self::assertArrayNotHasKey('x-undocumented', $op);
            self::assertSame('system:manage', $op['x-permission']);
        }
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    private static function findTheme(array $items, string $name): array
    {
        foreach ($items as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }
        self::fail("theme {$name} not listed");
    }
}
