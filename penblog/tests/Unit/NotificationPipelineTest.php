<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit;

use PenBlog\Notification\NotificationPipeline;
use PenBlog\Notification\NotificationRepository;
use PenBlog\Notification\NotifyRequest;
use PenBlog\Notification\PreferenceRepository;
use PenBlog\Tests\Integration\KernelTestCase;

/**
 * 05 五层管线:ingest 幂等/去重、route 三层偏好与静默、render、deliver、trace。
 */
final class NotificationPipelineTest extends KernelTestCase
{
    private NotificationPipeline $pipeline;
    private NotificationRepository $repo;
    private PreferenceRepository $prefs;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $c = $this->kernel->container;
        $this->pipeline = $c->get(NotificationPipeline::class);
        $this->repo = $c->get(NotificationRepository::class);
        $this->prefs = $c->get(PreferenceRepository::class);
        $this->adminId = $c->get(\PenBlog\Auth\UserRepository::class)->findByUsername('admin')->id();
    }

    private function notify(string $category = 'system', string $template = 'system.test', string $importance = 'normal', ?string $dedup = null): NotifyRequest
    {
        return new NotifyRequest(
            eventId: NotifyRequest::uuid(),
            category: $category,
            recipient: $this->adminId,
            templateKey: $template,
            payload: ['post_title' => '测试文章', 'author_name' => '访客', 'excerpt' => '你好', 'post_slug' => 'hello'],
            dedupKey: $dedup,
            link: '/admin/notifications',
            importance: $importance,
        );
    }

    public function testIngestRenderDeliverWithTrace(): void
    {
        $ids = $this->pipeline->process($this->notify());
        self::assertCount(1, $ids);
        $row = $this->repo->find($ids[0]);
        self::assertSame('delivered', $row['status']);
        self::assertSame('测试通知', $row['title']);
        $stages = array_column($this->repo->trace((string) $row['event_id']), 'stage');
        foreach (['ingest', 'route', 'render', 'deliver'] as $stage) {
            self::assertContains($stage, $stages);
        }
    }

    public function testEventIdIdempotent(): void
    {
        $req = $this->notify();
        $first = $this->pipeline->process($req);
        $second = $this->pipeline->process($req);
        self::assertSame($first, $second, '同 event_id 重放不得新增行');
        self::assertSame(1, $this->repo->list($this->adminId, [], 1, 10)['total']);
    }

    public function testDedupKeyMergesWithinWindow(): void
    {
        $first = $this->notify('comment', 'comment.new', 'normal', 'dedup-1');
        $this->pipeline->process($first);
        $second = $this->notify('comment', 'comment.new', 'normal', 'dedup-1');
        $ids = $this->pipeline->process($second);
        self::assertSame(1, $this->repo->list($this->adminId, ['category' => 'comment'], 1, 10)['total']);
        self::assertStringContainsString('deduped', json_encode($this->repo->trace($second->eventId)));
    }

    public function testUnknownCategoryProducesNothing(): void
    {
        $ids = $this->pipeline->process($this->notify('ghost'));
        self::assertSame([], $ids);
    }

    public function testPreferenceThreeLayerAndQuietWindow(): void
    {
        // 系统默认:comment = in_app + email
        $pref = $this->prefs->resolve($this->adminId, 'comment');
        self::assertTrue($pref['inherited']);
        self::assertSame(['in_app', 'email'], $pref['channels']);

        // 用户层覆盖:仅 in_app
        $this->prefs->save($this->adminId, 'comment', ['use_default' => false, 'channels' => ['in_app']]);
        $pref = $this->prefs->resolve($this->adminId, 'comment');
        self::assertFalse($pref['inherited']);
        self::assertSame(['in_app'], $pref['channels']);
    }

    public function testQuietWindowDropsEmailButCriticalPasses(): void
    {
        $this->prefs->save($this->adminId, 'comment', [
            'use_default' => false,
            'channels' => ['in_app', 'email'],
            'quiet_start' => '00:00',
            'quiet_end' => '23:59',
            'quiet_tz' => 'UTC',
        ]);
        $ids = $this->pipeline->process($this->notify('comment', 'comment.new'));
        $row = $this->repo->find($ids[0]);
        $channels = array_column(json_decode((string) $row['channels_json'], true), 'channel');
        self::assertSame(['in_app'], $channels, '静默期剔除 email');

        $ids = $this->pipeline->process($this->notify('comment', 'comment.new', 'critical'));
        $row = $this->repo->find($ids[0]);
        $channels = array_column(json_decode((string) $row['channels_json'], true), 'channel');
        self::assertSame(['in_app', 'email'], $channels, 'critical 直通静默');
    }

    public function testInAppAlwaysPresent(): void
    {
        $this->prefs->save($this->adminId, 'system', ['use_default' => false, 'channels' => ['email']]);
        $ids = $this->pipeline->process($this->notify('system'));
        $row = $this->repo->find($ids[0]);
        $channels = array_column(json_decode((string) $row['channels_json'], true), 'channel');
        self::assertContains('in_app', $channels);
        self::assertSame('delivered', $row['status']);
    }

    public function testUnknownTemplateRenderFailed(): void
    {
        $ids = $this->pipeline->process($this->notify('system', 'no.such.template'));
        $row = $this->repo->find($ids[0]);
        self::assertSame('render_failed', $row['status']);
        self::assertNotEmpty($this->repo->trace((string) $row['event_id']));
    }

    public function testRoleRecipientExpansion(): void
    {
        $users = $this->kernel->container->get(\PenBlog\Auth\UserRepository::class);
        $editor = $users->create('ed1', 'ed@example.com', 'password123', 1);
        $subs = $users->create('sub', 'sub@example.com', 'password123', 3);
        $req = new NotifyRequest(
            eventId: NotifyRequest::uuid(),
            category: 'content',
            recipient: 'role:manager',
            templateKey: 'post.published',
            payload: ['slug' => 'a', 'title' => 'T', 'author_name' => 'admin'],
        );
        $ids = $this->pipeline->process($req);
        self::assertCount(2, $ids, 'L0+L1 各一条,L2/L3 不收');
        $recipients = array_map(fn (int $id): int => (int) $this->repo->find($id)['recipient_id'], $ids);
        self::assertContains($editor->id(), $recipients);
        self::assertNotContains($subs->id(), $recipients);
    }
}
