<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\UserRepository;
use PenBlog\Webhook\WebhookDispatcher;
use PenBlog\Webhook\WebhookRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * 06 §8 Webhook 管理 API:权限、secret 明文仅一次、列表掩码、test 端点、投递记录、参数校验。
 */
final class WebhookApiTest extends KernelTestCase
{
    private array $cookies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cookies = $this->login('admin', 'password123');
    }

    /** @param array<string,mixed>|null $json */
    private function send(string $method, string $path, ?array $json = null): ResponseInterface
    {
        return $this->kernel->handle($this->authRequest($method, $path, $this->cookies, $json));
    }

    /** 用 fake sender 替换出站投递,避免测试触达真实网络。 */
    private function fakeOutbound(bool $ok = true): void
    {
        $sender = static fn (): array => $ok
            ? ['ok' => true, 'status' => 200, 'error' => '', 'retryable' => false]
            : ['ok' => false, 'status' => 500, 'error' => 'http_500', 'retryable' => true];
        $this->kernel->container->instance(WebhookDispatcher::class, new WebhookDispatcher(
            $this->kernel->container->get(WebhookRepository::class),
            $this->kernel->container->get(LoggerInterface::class),
            $sender,
        ));
    }

    public function testAnonymousRejected(): void
    {
        // 与 RBAC 矩阵同语义:权限型端点匿名一律 403(Gate::require)
        self::assertSame(403, $this->kernel->handle($this->request('GET', '/api/v1/webhooks'))->getStatusCode());
        self::assertSame(403, $this->kernel->handle($this->request('POST', '/api/v1/webhooks', [
            'url' => 'http://a.example/hook', 'events' => ['*'],
        ]))->getStatusCode());
    }

    public function testLowLevelUserForbidden(): void
    {
        $this->kernel->container->get(UserRepository::class)->create('sub1', 'sub@example.com', 'password123', 3);
        $cookies = $this->login('sub1', 'password123');
        self::assertSame(403, $this->kernel->handle($this->authRequest('GET', '/api/v1/webhooks', $cookies))->getStatusCode());
        self::assertSame(403, $this->kernel->handle($this->authRequest('POST', '/api/v1/webhooks', $cookies, [
            'url' => 'http://a.example/hook', 'events' => ['*'],
        ]))->getStatusCode());
    }

    public function testCreateReturnsPlaintextSecretOnceAndListMasksIt(): void
    {
        $res = $this->send('POST', '/api/v1/webhooks', [
            'url' => 'http://hook.example/ep', 'events' => ['post.published', 'comment.created'],
        ]);
        self::assertSame(201, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);
        $secret = $data['webhook']['secret'];
        self::assertStringStartsWith('whsec_', $secret);
        self::assertNotEmpty($data['webhook']['id']);

        $res = $this->send('GET', '/api/v1/webhooks');
        self::assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $items = json_decode($body, true)['items'];
        self::assertCount(1, $items);
        self::assertSame(['post.published', 'comment.created'], $items[0]['events']);
        self::assertStringStartsWith(substr($secret, 0, 10), $items[0]['secretPreview']);
        self::assertStringNotContainsString($secret, $body, '列表响应不得再出现明文 secret');
    }

    public function testCustomSecretPreserved(): void
    {
        $res = $this->send('POST', '/api/v1/webhooks', [
            'url' => 'https://hook.example/ep', 'events' => ['*'], 'secret' => 'my_precious',
        ]);
        self::assertSame(201, $res->getStatusCode());
        self::assertSame('my_precious', json_decode((string) $res->getBody(), true)['webhook']['secret']);
    }

    public function testValidationErrors(): void
    {
        foreach ([
            ['url' => 'ftp://a.example', 'events' => ['*']],
            ['url' => 'http://a.example', 'events' => []],
            ['url' => 'http://a.example', 'events' => ['no.such.event']],
        ] as $i => $body) {
            $res = $this->send('POST', '/api/v1/webhooks', $body);
            self::assertSame(400, $res->getStatusCode(), "case {$i}: " . (string) $res->getBody());
        }
        $res = $this->send('POST', '/api/v1/webhooks', ['events' => ['*']]);
        self::assertSame(400, $res->getStatusCode());
    }

    public function testTestEndpointAndDeliveries(): void
    {
        $this->fakeOutbound();
        $id = json_decode((string) $this->send('POST', '/api/v1/webhooks', [
            'url' => 'http://hook.example/ep', 'events' => ['post.published'],
        ])->getBody(), true)['webhook']['id'];

        $res = $this->send('POST', "/api/v1/webhooks/{$id}:test");
        self::assertSame(200, $res->getStatusCode());
        $deliveryId = json_decode((string) $res->getBody(), true)['delivery'];

        $res = $this->send('GET', "/api/v1/webhooks/{$id}/deliveries");
        self::assertSame(200, $res->getStatusCode());
        $items = json_decode((string) $res->getBody(), true)['items'];
        self::assertCount(1, $items);
        self::assertSame($deliveryId, $items[0]['id']);
        self::assertSame('webhook.test', $items[0]['event']);
        self::assertSame('delivered', $items[0]['status']);
        self::assertArrayNotHasKey('payload', $items[0], '投递列表不返回 payload 原文');

        self::assertSame(404, $this->send('GET', '/api/v1/webhooks/999/deliveries')->getStatusCode());
        self::assertSame(404, $this->send('POST', '/api/v1/webhooks/999:test')->getStatusCode());
    }

    public function testFailedDeliveryRecordedAsRetryable(): void
    {
        $this->fakeOutbound(ok: false);
        $id = json_decode((string) $this->send('POST', '/api/v1/webhooks', [
            'url' => 'http://hook.example/ep', 'events' => ['*'],
        ])->getBody(), true)['webhook']['id'];

        $this->send('POST', "/api/v1/webhooks/{$id}:test");
        $items = json_decode((string) $this->send('GET', "/api/v1/webhooks/{$id}/deliveries")->getBody(), true)['items'];
        self::assertSame('failed', $items[0]['status']);
        self::assertNotNull($items[0]['next_retry_at']);
    }

    public function testDeleteFlow(): void
    {
        $id = json_decode((string) $this->send('POST', '/api/v1/webhooks', [
            'url' => 'http://hook.example/ep', 'events' => ['*'],
        ])->getBody(), true)['webhook']['id'];

        self::assertSame(200, $this->send('DELETE', "/api/v1/webhooks/{$id}")->getStatusCode());
        self::assertSame(404, $this->send('DELETE', "/api/v1/webhooks/{$id}")->getStatusCode());
        self::assertSame(0, count(json_decode((string) $this->send('GET', '/api/v1/webhooks')->getBody(), true)['items']));
    }
}
