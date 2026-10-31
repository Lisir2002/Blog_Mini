<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\UserRepository;
use PenBlog\Auth\XpService;

/**
 * 09 §2/§8 经验 API:匿名 403、设置读写、手工发放幂等、OpenAPI 登记。
 */
final class XpApiTest extends KernelTestCase
{
    private array $adminCookies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminCookies = $this->login('admin', 'password123');
    }

    public function testAnonymousForbidden(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/api/v1/xp/settings'));
        self::assertSame(403, $res->getStatusCode());
        $res = $this->kernel->handle($this->request('PUT', '/api/v1/xp/settings', ['enabled' => true]));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testSettingsRoundTrip(): void
    {
        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/xp/settings', $this->adminCookies));
        self::assertSame(200, $res->getStatusCode());
        $settings = json_decode((string) $res->getBody(), true)['settings'];
        self::assertFalse($settings['enabled'], '默认关闭');
        self::assertNotEmpty($settings['rules']);

        $res = $this->kernel->handle($this->authRequest('PUT', '/api/v1/xp/settings', $this->adminCookies, [
            'enabled' => true,
            'rules' => [['metric' => 'comments_posted', 'xp_per_unit' => 20, 'per_day_cap' => null]],
            'thresholds' => ['2' => 100, '1' => 500],
        ]));
        self::assertSame(200, $res->getStatusCode());

        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/xp/settings', $this->adminCookies));
        $after = json_decode((string) $res->getBody(), true)['settings'];
        self::assertTrue($after['enabled']);
        self::assertSame(20, $after['rules'][0]['xp_per_unit']);
        self::assertSame(500, $after['thresholds']['1']);
    }

    public function testInvalidSettingsRejected(): void
    {
        $res = $this->kernel->handle($this->authRequest('PUT', '/api/v1/xp/settings', $this->adminCookies, [
            'rules' => 'not-an-array',
        ]));
        self::assertSame(400, $res->getStatusCode());

        $res = $this->kernel->handle($this->authRequest('PUT', '/api/v1/xp/settings', $this->adminCookies, [
            'rules' => [['metric' => 'comments_posted', 'xp_per_unit' => 999999]],
        ]));
        self::assertSame(400, $res->getStatusCode());
    }

    public function testGrantWhileDisabledReturnsDisabledStatus(): void
    {
        $this->kernel->container->get(UserRepository::class)->create('bob', 'bob@example.com', 'password123', 3);

        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/users/bob/xp:grant', $this->adminCookies, [
            'metric' => 'comments_posted', 'amount' => 2, 'eventId' => 'manual-1',
        ]));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('disabled', json_decode((string) $res->getBody(), true)['grant']['status'], '关闭时不记账');
    }

    public function testGrantAfterEnabled(): void
    {
        $this->kernel->container->get(XpService::class)->saveSettings(['enabled' => true]);
        $this->kernel->container->get(UserRepository::class)->create('bob', 'bob@example.com', 'password123', 3);

        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/users/bob/xp:grant', $this->adminCookies, [
            'metric' => 'comments_posted', 'amount' => 2, 'eventId' => 'manual-1',
        ]));
        self::assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);
        self::assertSame('granted', $data['grant']['status']);
        self::assertSame(30, $data['grant']['xp']);
        self::assertSame(30, $data['user']['xp']);

        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/users/bob/xp:grant', $this->adminCookies, [
            'metric' => 'comments_posted', 'amount' => 2, 'eventId' => 'manual-1',
        ]));
        self::assertSame('skipped', json_decode((string) $res->getBody(), true)['grant']['status']);

        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/users/nobody/xp:grant', $this->adminCookies, [
            'metric' => 'comments_posted',
        ]));
        self::assertSame(404, $res->getStatusCode());

        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/users/bob/xp:grant', $this->adminCookies, [
            'metric' => 'bogus',
        ]));
        self::assertSame(400, $res->getStatusCode());
    }

    public function testOpenApiDocumentsXp(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/api/v1/openapi.json'));
        $spec = json_decode((string) $res->getBody(), true);
        self::assertSame('system:manage', $spec['paths']['/api/v1/xp/settings']['get']['x-permission']);
        self::assertArrayHasKey('put', $spec['paths']['/api/v1/xp/settings']);
        $grant = $spec['paths']['/api/v1/users/{name}/xp:grant']['post'];
        self::assertSame('system:manage', $grant['x-permission']);
        self::assertFalse(($grant['security'] ?? []) === [], '发放端点需认证');
    }

    public function testAdminPageRequiresManage(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/admin/xp'));
        self::assertSame(302, $res->getStatusCode(), '匿名 → 登录');

        $res = $this->kernel->handle($this->authRequest('GET', '/admin/xp', $this->adminCookies));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        self::assertStringContainsString('经验系统', $html);
        self::assertStringContainsString('/admin/xp', $html);
        self::assertStringContainsString('保存经验设置', $html);
    }
}
