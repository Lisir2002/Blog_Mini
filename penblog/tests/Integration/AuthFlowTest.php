<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\SessionStore;

final class AuthFlowTest extends KernelTestCase
{
    public function testLoginSetsSessionAndCsrfCookies(): void
    {
        $response = $this->kernel->handle($this->request('POST', '/api/v1/auth/login', [
            'username' => 'admin', 'password' => 'password123',
        ]));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('admin', $data['user']['username']);
        self::assertSame('管理员', $data['user']['levelTitle']);

        $cookies = self::parseSetCookies($response->getHeader('Set-Cookie'));
        self::assertNotEmpty($cookies[SessionStore::COOKIE]);
        self::assertNotEmpty($cookies[SessionStore::CSRF_COOKIE]);
        // 会话 Cookie 必须 HttpOnly,原始 token 不落库
        $sessionLine = $response->getHeader('Set-Cookie')[0];
        self::assertStringContainsString('HttpOnly', $sessionLine);
    }

    public function testLoginRejectsBadPassword(): void
    {
        $response = $this->kernel->handle($this->request('POST', '/api/v1/auth/login', [
            'username' => 'admin', 'password' => 'wrong-password',
        ]));
        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_credentials', $data['error']['code']);
    }

    public function testMeEndpointRequiresAuth(): void
    {
        $response = $this->kernel->handle($this->request('GET', '/api/v1/user/me'));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testSessionCookieAuthenticatesMe(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->request('GET', '/api/v1/user/me', null, [], $cookies));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('admin', $data['user']['username']);
        self::assertContains('*', $data['user']['permissions']);
        self::assertArrayNotHasKey('password', $data['user']);
    }

    public function testUnsafeApiWithoutCsrfHeaderIs403(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->request(
            'PUT', '/api/v1/user/me', ['displayName' => 'Hacked'], [], $cookies,
        ));
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('csrf_failed', $data['error']['code']);
    }

    public function testUpdateProfileWithCsrf(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/user/me', $cookies, [
            'displayName' => '新昵称', 'bio' => '你好',
        ]));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('新昵称', $data['user']['displayName']);
        self::assertSame('你好', $data['user']['bio']);
    }

    public function testPasswordChangeRequiresOldPassword(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/user/me', $cookies, [
            'password' => 'brandnewpass',
        ]));
        self::assertSame(400, $response->getStatusCode());

        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/user/me', $cookies, [
            'password' => 'brandnewpass', 'oldPassword' => 'password123',
        ]));
        self::assertSame(200, $response->getStatusCode());

        // 旧密码失效,新密码可登录
        $response = $this->kernel->handle($this->request('POST', '/api/v1/auth/login', [
            'username' => 'admin', 'password' => 'password123',
        ]));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testLoginRotatesSessionsAndLogoutInvalidates(): void
    {
        $first = $this->login('admin', 'password123');
        self::assertNotSame($first[SessionStore::COOKIE], $this->login('admin', 'password123')[SessionStore::COOKIE]);
        // 旧会话因轮换失效
        $response = $this->kernel->handle($this->request('GET', '/api/v1/user/me', null, [], $first));
        self::assertSame(401, $response->getStatusCode());

        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/auth/logout', $cookies));
        self::assertSame(200, $response->getStatusCode());
        $response = $this->kernel->handle($this->request('GET', '/api/v1/user/me', null, [], $cookies));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testPatBearerAuthAndScopeIntersection(): void
    {
        // 作者(L2)发 PAT:权限 = 等级权限 ∩ scopes
        $this->kernel->container->get(\PenBlog\Auth\UserRepository::class)
            ->create('author1', 'a1@example.com', 'password123', 2);
        $cookies = $this->login('author1', 'password123');
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/tokens', $cookies, [
            'name' => 'ci', 'scopes' => ['comment:create'],
        ]));
        self::assertSame(201, $response->getStatusCode());
        $raw = json_decode((string) $response->getBody(), true)['token']['raw'];
        self::assertMatchesRegularExpression('/^pb_[0-9a-f]{40}$/', $raw);

        // Bearer 不需要 CSRF
        $response = $this->kernel->handle($this->request('GET', '/api/v1/user/me', null, ['Authorization' => 'Bearer ' . $raw]));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(['comment:create'], $data['user']['permissions']);

        // 撤销后失效
        $id = $this->tokenId($cookies, $raw);
        $response = $this->kernel->handle($this->authRequest('DELETE', '/api/v1/tokens/' . $id, $cookies));
        self::assertSame(200, $response->getStatusCode());
        $response = $this->kernel->handle($this->request('GET', '/api/v1/user/me', null, ['Authorization' => 'Bearer ' . $raw]));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testAdminPatKeepsWildcard(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/tokens', $cookies, ['name' => 'ci']));
        $raw = json_decode((string) $response->getBody(), true)['token']['raw'];
        $response = $this->kernel->handle($this->request('GET', '/api/v1/user/me', null, ['Authorization' => 'Bearer ' . $raw]));
        $data = json_decode((string) $response->getBody(), true);
        self::assertContains('*', $data['user']['permissions']);
    }

    public function testRegisterClosedByDefault(): void
    {
        $response = $this->kernel->handle($this->request('POST', '/api/v1/auth/register', [
            'username' => 'newbie', 'email' => 'n@example.com', 'password' => 'longenough',
        ]));
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('registration_closed', $data['error']['code']);

        $this->kernel->options()->set('auth.registration', 'open');
        $response = $this->kernel->handle($this->request('POST', '/api/v1/auth/register', [
            'username' => 'newbie', 'email' => 'n@example.com', 'password' => 'longenough',
        ]));
        self::assertSame(201, $response->getStatusCode());
    }

    public function testAccessLevelsVisibility(): void
    {
        // 匿名:无 permissions
        $response = $this->kernel->handle($this->request('GET', '/api/v1/access-levels'));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(4, $data['items']);
        self::assertArrayNotHasKey('permissions', $data['items'][0]);

        // L0:可见
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->request('GET', '/api/v1/access-levels', null, [], $cookies));
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('permissions', $data['items'][0]);
    }

    public function testCsrfEndpoint(): void
    {
        $response = $this->kernel->handle($this->request('GET', '/api/v1/auth/csrf'));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $data['token']);
    }

    /** @return int 按 raw 找到 token 行 id */
    private function tokenId(array $cookies, string $raw): int
    {
        $response = $this->kernel->handle($this->request('GET', '/api/v1/tokens', null, [], $cookies));
        $items = json_decode((string) $response->getBody(), true)['items'];
        $prefix = substr($raw, 3, 8);
        foreach ($items as $item) {
            if ($item['token_prefix'] === $prefix) {
                return (int) $item['id'];
            }
        }
        self::fail('token not listed');
        return 0;
    }
}
