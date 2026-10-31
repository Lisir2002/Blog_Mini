<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\UserRepository;
use PenBlog\Auth\XpService;
use PenBlog\Notification\NotificationRepository;

/**
 * 09 §2 事件驱动经验:待审评论不计、过审评论/发布文章记账、
 * 达阈值自动晋升 + system 通知、L0 不受经验影响。
 */
final class XpEventsTest extends KernelTestCase
{
    private array $adminCookies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminCookies = $this->login('admin', 'password123');
        $this->kernel->container->get(XpService::class)->saveSettings([
            'enabled' => true,
            'thresholds' => ['1' => 30],
        ]);
    }

    private function publishPost(string $title): string
    {
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->adminCookies, [
            'title' => $title, 'bodyMd' => 'x', 'status' => 'published',
        ]));
        self::assertSame(201, $res->getStatusCode());
        return json_decode((string) $res->getBody(), true)['metadata']['name'];
    }

    public function testPendingCommentDoesNotGrant(): void
    {
        $slug = $this->publishPost('待审实验');
        $users = $this->kernel->container->get(UserRepository::class);
        $users->create('bob', 'bob@example.com', 'password123', 3);
        $bob = $this->login('bob', 'password123');
        $res = $this->kernel->handle($this->authRequest('POST', "/api/v1/posts/{$slug}/comments", $bob, ['body' => '灌水']));
        self::assertSame('pending', json_decode((string) $res->getBody(), true)['comment']['status']);
        self::assertSame(0, self::xpOf($users->findByUsername('bob')->id()), '待审评论不计经验');
    }

    public function testApprovedCommentGrantsXp(): void
    {
        $slug = $this->publishPost('编辑来过');
        $users = $this->kernel->container->get(UserRepository::class);
        $users->create('ed1', 'ed@example.com', 'password123', 1);
        $ed = $this->login('ed1', 'password123');
        $res = $this->kernel->handle($this->authRequest('POST', "/api/v1/posts/{$slug}/comments", $ed, ['body' => '过审评论']));
        self::assertSame('approved', json_decode((string) $res->getBody(), true)['comment']['status']);
        self::assertSame(15, self::xpOf($users->findByUsername('ed1')->id()));
    }

    public function testPublishPromotesAuthorAndSendsSystemNotification(): void
    {
        $users = $this->kernel->container->get(UserRepository::class);
        $carol = $users->create('carol', 'carol@example.com', 'password123', 2);
        $carolCookies = $this->login('carol', 'password123');

        // posts_published 默认 40 xp/篇 ≥ 阈值 30 → L2 → L1
        $res = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $carolCookies, [
            'title' => '卡罗尔首发', 'bodyMd' => 'x', 'status' => 'published',
        ]));
        self::assertSame(201, $res->getStatusCode());

        $fresh = $users->findById($carol->id());
        self::assertSame(40, $fresh->xp());
        self::assertSame(1, $fresh->level(), '达阈值自动晋升 L2→L1');

        $repo = $this->kernel->container->get(NotificationRepository::class);
        $list = $repo->list($carol->id(), ['category' => 'system'], 1, 10);
        self::assertSame(1, $list['total']);
        self::assertStringContainsString('等级晋升', $list['items'][0]['title']);
    }

    public function testAdminXpAccumulatesButLevelUntouched(): void
    {
        $this->publishPost('管理员发文一');
        $this->publishPost('管理员发文二');
        $users = $this->kernel->container->get(UserRepository::class);
        $admin = $users->findByUsername('admin');
        self::assertSame(80, $admin->xp(), 'L0 也记账(审计用)');
        self::assertSame(0, $admin->level(), '但 L0 永不因经验变动');
    }

    private function xpOf(int $userId): int
    {
        $stmt = $this->kernel->db()->pdo()->prepare('SELECT xp FROM users WHERE id = :i');
        $stmt->execute(['i' => $userId]);
        return (int) $stmt->fetchColumn();
    }
}
