<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\SessionStore;

/**
 * 安全红线用例(09 §4 / 08 §1):SQLi 预处理白名单、模板/XSS 转义、会话固定防护。
 */
final class SecurityTest extends KernelTestCase
{
    public function testSqlInjectionInFiltersIsNeutralised(): void
    {
        $cookies = $this->login('admin', 'password123');
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, [
            'title' => '正常文章',
            'bodyMd' => 'hi',
            'slug' => 'normal-post',
            'status' => 'published',
        ]));

        // 注入串只会作为绑定参数比较,匹配不到任何作者 → 空列表而非全量泄露
        $response = $this->kernel->handle(
            $this->request('GET', '/api/v1/posts')->withQueryParams(['filter' => ['author' => "admin' OR '1'='1"]])
        );
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['items']);

        // 排序字段白名单:非法 sort 列被丢弃,不报错也不注入
        $response = $this->kernel->handle(
            $this->request('GET', '/api/v1/posts')->withQueryParams(['sort' => ['(SELECT 1)--']])
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, json_decode((string) $response->getBody(), true)['items']);

        // 路径参数含引号:仅查无此人,不炸库
        $response = $this->kernel->handle($this->request('GET', "/posts/nope'--"));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testXssInPostTitleAndBodyIsEscapedOnThemePage(): void
    {
        $cookies = $this->login('admin', 'password123');
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, [
            'title' => '<script>alert(1)</script>',
            'bodyMd' => "<script>evil()</script>\n\n<img src=x onerror=alert(2)>\n\n[xss](javascript:alert(3))",
            'slug' => 'xss-probe',
            'status' => 'published',
        ]));

        $response = $this->kernel->handle($this->request('GET', '/posts/xss-probe'));
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<script>evil()</script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testXssInCommentIsEscapedOnThemePage(): void
    {
        $cookies = $this->login('admin', 'password123');
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, [
            'title' => '评论区',
            'bodyMd' => '正文',
            'slug' => 'comment-xss',
            'status' => 'published',
        ]));

        // 主题评论表单端点:JSON 体 + _csrf 手动双提交
        $response = $this->kernel->handle($this->request(
            'POST',
            '/posts/comment-xss/comments',
            ['body' => '<script>comment-evil()</script>', '_csrf' => $cookies[SessionStore::CSRF_COOKIE]],
            [],
            $cookies,
        ));
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        $html = (string) $this->kernel->handle($this->request('GET', '/posts/comment-xss'))->getBody();
        self::assertStringNotContainsString('<script>comment-evil()</script>', $html);
        self::assertStringContainsString('&lt;script&gt;comment-evil()', $html);
    }

    public function testCommentFormRejectsCsrfMismatch(): void
    {
        $cookies = $this->login('admin', 'password123');
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, [
            'title' => '守卫', 'bodyMd' => 'x', 'slug' => 'csrf-guard', 'status' => 'published',
        ]));

        $response = $this->kernel->handle($this->request(
            'POST',
            '/posts/csrf-guard/comments',
            ['body' => '恶意评论', '_csrf' => 'wrong-token'],
            [],
            $cookies,
        ));
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('error=csrf', $response->getHeaderLine('Location'));

        $html = (string) $this->kernel->handle($this->request('GET', '/posts/csrf-guard'))->getBody();
        self::assertStringNotContainsString('恶意评论', $html);
    }

    public function testSessionFixationPreventedOnLogin(): void
    {
        $first = $this->login('admin', 'password123');
        $me = $this->kernel->handle($this->request('GET', '/api/v1/user/me', null, [], $first));
        self::assertSame(200, $me->getStatusCode());

        $second = $this->login('admin', 'password123');
        self::assertNotSame($first[SessionStore::COOKIE], $second[SessionStore::COOKIE], '登录必须轮换会话');

        // 旧会话已撤销(登录即 revokeAllForUser)
        $stale = $this->kernel->handle($this->request('GET', '/api/v1/user/me', null, [], $first));
        self::assertSame(401, $stale->getStatusCode());
    }

    public function testAdminFormWithoutCsrfIsRejected(): void
    {
        $cookies = $this->login('admin', 'password123');
        // 只带会话 Cookie、不带 CSRF:后台前缀的不安全方法应被拦截
        $response = $this->kernel->handle($this->request('POST', '/admin/logout', null, [], [
            SessionStore::COOKIE => $cookies[SessionStore::COOKIE],
        ]));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('csrf_failed', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testAdminLoginRotatesSessionAndSetsCookies(): void
    {
        $response = $this->kernel->handle($this->request(
            'POST',
            '/admin/login',
            ['username' => 'admin', 'password' => 'password123'],
            ['Content-Type' => 'application/json'],
        ));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/dashboard', $response->getHeaderLine('Location'));
        $cookies = self::parseSetCookies($response->getHeader('Set-Cookie'));
        self::assertNotSame('', $cookies[SessionStore::COOKIE] ?? '');

        $dashboard = $this->kernel->handle($this->request('GET', '/admin/dashboard', null, [], [
            SessionStore::COOKIE => $cookies[SessionStore::COOKIE],
        ]));
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('内容 · 文章', (string) $dashboard->getBody());
    }
}
