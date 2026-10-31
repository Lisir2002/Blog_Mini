<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Notification\NotificationRepository;

/**
 * 05 §3 事件接线:发布 → content 通知;评论 → pending/新评论通知;后台页渲染。
 */
final class NotificationEventsTest extends KernelTestCase
{
    private array $adminCookies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminCookies = $this->login('admin', 'password123');
    }

    public function testPublishNotifiesManagers(): void
    {
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->adminCookies, [
            'title' => '发布公告',
            'bodyMd' => '正文',
            'status' => 'published',
        ]));
        self::assertSame(201, $res->getStatusCode());

        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/notifications?filter%5Bcategory%5D=content', $this->adminCookies));
        $data = json_decode((string) $res->getBody(), true);
        self::assertSame(1, $data['total']);
        self::assertStringContainsString('文章已发布', $data['items'][0]['title']);
        self::assertStringContainsString('发布公告', (string) $data['items'][0]['bodyHtml']);
        self::assertSame('delivered', $data['items'][0]['status']);
    }

    public function testDraftUpdateDoesNotResend(): void
    {
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->adminCookies, [
            'title' => '首发', 'bodyMd' => 'x', 'status' => 'published',
        ]));
        $slug = json_decode((string) $res->getBody(), true)['metadata']['name'];
        // 再次保存(已 published)→ 不再产生 content 通知
        $this->kernel->handle($this->authRequest('PUT', '/api/v1/posts/' . $slug, $this->adminCookies, ['status' => 'published', 'title' => '首发']));
        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/notifications?filter%5Bcategory%5D=content', $this->adminCookies));
        self::assertSame(1, json_decode((string) $res->getBody(), true)['total']);
    }

    public function testPendingCommentNotifiesAdmin(): void
    {
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->adminCookies, [
            'title' => '被评论的文章', 'bodyMd' => 'x', 'status' => 'published',
        ]));
        $slug = json_decode((string) $res->getBody(), true)['metadata']['name'];

        $users = $this->kernel->container->get(\PenBlog\Auth\UserRepository::class);
        $users->create('bob', 'bob@example.com', 'password123', 3);
        $bob = $this->login('bob', 'password123');
        $res = $this->kernel->handle($this->authRequest('POST', "/api/v1/posts/{$slug}/comments", $bob, ['body' => '写得真好']));
        self::assertSame(201, $res->getStatusCode());
        self::assertSame('pending', json_decode((string) $res->getBody(), true)['comment']['status']);

        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/notifications?filter%5Bcategory%5D=comment', $this->adminCookies));
        $data = json_decode((string) $res->getBody(), true);
        self::assertSame(1, $data['total'], '管理员收到待审通知');
        self::assertStringContainsString('待审核', $data['items'][0]['title']);
    }

    public function testApprovedCommentNotifiesPostAuthor(): void
    {
        // admin 发文;editor(L1)过审评论 → admin(文章作者)收 comment.new
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->adminCookies, [
            'title' => '作者文章', 'bodyMd' => 'x', 'status' => 'published',
        ]));
        $slug = json_decode((string) $res->getBody(), true)['metadata']['name'];

        $users = $this->kernel->container->get(\PenBlog\Auth\UserRepository::class);
        $users->create('ed1', 'ed@example.com', 'password123', 1);
        $ed = $this->login('ed1', 'password123');
        $res = $this->kernel->handle($this->authRequest('POST', "/api/v1/posts/{$slug}/comments", $ed, ['body' => '编辑评论']));
        self::assertSame('approved', json_decode((string) $res->getBody(), true)['comment']['status']);

        $res = $this->kernel->handle($this->authRequest('GET', '/api/v1/notifications?filter%5Bcategory%5D=comment', $this->adminCookies));
        $data = json_decode((string) $res->getBody(), true);
        self::assertSame(1, $data['total']);
        self::assertStringContainsString('作者文章', $data['items'][0]['title']);
    }

    public function testAdminNotificationsPageAndBell(): void
    {
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->adminCookies, [
            'title' => '冒烟发布', 'bodyMd' => 'x', 'status' => 'published',
        ]));
        $res = $this->kernel->handle($this->authRequest('GET', '/admin/notifications', $this->adminCookies));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        self::assertStringContainsString('通知中心', $html);
        self::assertStringContainsString('bell-badge', $html);
        self::assertStringContainsString('冒烟发布', $html);

        $res = $this->kernel->handle($this->authRequest('GET', '/admin/notifications/preferences', $this->adminCookies));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('通知偏好', (string) $res->getBody());

        // 匿名访问后台 → 302 登录
        $res = $this->kernel->handle($this->request('GET', '/admin/notifications'));
        self::assertSame(302, $res->getStatusCode());
    }

    public function testThemeCommentFormDispatchesEvent(): void
    {
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->adminCookies, [
            'title' => '主题评论', 'bodyMd' => 'x', 'status' => 'published',
        ]));
        $slug = json_decode((string) $res->getBody(), true)['metadata']['name'];
        $users = $this->kernel->container->get(\PenBlog\Auth\UserRepository::class);
        $users->create('ann', 'ann@example.com', 'password123', 3);
        $ann = $this->login('ann', 'password123');
        $res = $this->kernel->handle($this->request(
            'POST',
            "/posts/{$slug}/comments",
            ['body' => '主题表单评论', '_csrf' => $ann[\PenBlog\Auth\SessionStore::CSRF_COOKIE]],
            [],
            $ann,
        ));
        self::assertSame(302, $res->getStatusCode());
        $repo = $this->kernel->container->get(NotificationRepository::class);
        $adminId = $users->findByUsername('admin')->id();
        self::assertSame(1, $repo->list($adminId, ['category' => 'comment'], 1, 10)['total']);
    }
}
