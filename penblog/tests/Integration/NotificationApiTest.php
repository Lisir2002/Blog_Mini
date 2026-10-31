<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Notification\NotificationPipeline;
use PenBlog\Notification\NotifyRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * 05 §7 通知 API:列表/已读/归档/偏好/未读计数/轨迹权限。
 */
final class NotificationApiTest extends KernelTestCase
{
    private array $cookies;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cookies = $this->login('admin', 'password123');
        $this->adminId = $this->kernel->container->get(\PenBlog\Auth\UserRepository::class)->findByUsername('admin')->id();
    }

    /** @param array<string,mixed>|null $json */
    private function send(string $method, string $path, ?array $json = null): ResponseInterface
    {
        return $this->kernel->handle($this->authRequest($method, $path, $this->cookies, $json));
    }

    private function push(string $category = 'system', string $template = 'system.test'): int
    {
        $ids = $this->kernel->container->get(NotificationPipeline::class)
            ->process(new NotifyRequest(
                eventId: NotifyRequest::uuid(),
                category: $category,
                recipient: $this->adminId,
                templateKey: $template,
                payload: ['post_title' => 'P', 'author_name' => 'A', 'excerpt' => 'E', 'post_slug' => 's', 'slug' => 's', 'title' => 'P'],
                link: '/admin/notifications',
            ));
        return $ids[0];
    }

    public function testAnonymousListRejected(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/api/v1/notifications'));
        self::assertSame(401, $res->getStatusCode());
    }

    public function testListUnreadReadAndArchive(): void
    {
        $id = $this->push();
        $res = $this->send('GET', '/api/v1/notifications');
        self::assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);
        self::assertSame(1, $data['total']);
        self::assertSame($id, $data['items'][0]['id']);

        $res = $this->send('GET', '/api/v1/notifications/unread-count');
        self::assertSame(1, json_decode((string) $res->getBody(), true)['count']);

        $res = $this->send('POST', "/api/v1/notifications/{$id}:read");
        self::assertSame(200, $res->getStatusCode());
        $res = $this->send('GET', '/api/v1/notifications?filter%5Bunread%5D=1');
        self::assertSame(0, json_decode((string) $res->getBody(), true)['total'], '已读后不再出现在未读过滤');

        $res = $this->send('POST', "/api/v1/notifications/{$id}:archive");
        self::assertSame(200, $res->getStatusCode());
        $res = $this->send('GET', '/api/v1/notifications');
        self::assertSame(0, json_decode((string) $res->getBody(), true)['total']);

        $res = $this->send('POST', '/api/v1/notifications/999999:read');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testReadAll(): void
    {
        $this->push();
        $this->push();
        $res = $this->send('POST', '/api/v1/notifications/read-all');
        self::assertSame(2, json_decode((string) $res->getBody(), true)['updated']);
        $res = $this->send('GET', '/api/v1/notifications/unread-count');
        self::assertSame(0, json_decode((string) $res->getBody(), true)['count']);
    }

    public function testPreferencesGetAndPut(): void
    {
        $res = $this->send('GET', '/api/v1/notifications/preferences');
        self::assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);
        self::assertCount(4, $data['items']);
        self::assertTrue($data['items'][0]['inherited']);

        $res = $this->send('PUT', '/api/v1/notifications/preferences', [
            'category' => 'comment',
            'use_default' => false,
            'channels' => ['in_app'],
            'quiet_start' => '22:00',
            'quiet_end' => '07:00',
            'quiet_tz' => 'Asia/Shanghai',
        ]);
        self::assertSame(200, $res->getStatusCode());
        $res = $this->send('GET', '/api/v1/notifications/preferences');
        $items = json_decode((string) $res->getBody(), true)['items'];
        $row = null;
        foreach ($items as $item) {
            if ($item['category'] === 'comment') {
                $row = $item;
            }
        }
        self::assertNotNull($row);
        self::assertFalse($row['inherited']);
        self::assertSame(['in_app'], $row['channels']);
        self::assertSame('22:00', $row['quiet']['start']);
    }

    public function testPreferencesValidation(): void
    {
        $res = $this->send('PUT', '/api/v1/notifications/preferences', ['category' => 'ghost']);
        self::assertSame(400, $res->getStatusCode());
        $res = $this->send('PUT', '/api/v1/notifications/preferences', [
            'category' => 'comment', 'use_default' => false, 'channels' => ['carrier_pigeon'],
        ]);
        self::assertSame(400, $res->getStatusCode());
        $res = $this->send('PUT', '/api/v1/notifications/preferences', [
            'category' => 'comment', 'use_default' => false, 'channels' => ['in_app'], 'quiet_start' => '25:99',
        ]);
        self::assertSame(400, $res->getStatusCode());
    }

    public function testTracesRequireSystemManage(): void
    {
        $id = $this->push();
        $eventId = (string) $this->kernel->container->get(\PenBlog\Notification\NotificationRepository::class)->find($id)['event_id'];
        $res = $this->send('GET', "/api/v1/notifications/traces/{$eventId}");
        self::assertSame(200, $res->getStatusCode());
        self::assertNotEmpty(json_decode((string) $res->getBody(), true)['items']);

        $this->kernel->container->get(\PenBlog\Auth\UserRepository::class)->create('low', 'low@example.com', 'password123', 3);
        $cookies = $this->login('low', 'password123');
        $res = $this->kernel->handle($this->authRequest('GET', "/api/v1/notifications/traces/{$eventId}", $cookies));
        self::assertSame(403, $res->getStatusCode());
    }
}
