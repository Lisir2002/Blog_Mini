<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit;

use PenBlog\Notification\Events\CommentCreated;
use PenBlog\Notification\Events\PostPublished;
use PenBlog\Tests\Integration\KernelTestCase;
use PenBlog\Webhook\WebhookDispatcher;
use PenBlog\Webhook\WebhookRepository;
use Psr\Log\LoggerInterface;

/**
 * 06 §8 Webhook 出站扇出:事件匹配、HMAC 签名、退避/死信语义、retryDue、EventBus 接线。
 */
final class WebhookDispatcherTest extends KernelTestCase
{
    private WebhookRepository $repo;
    private WebhookDispatcher $dispatcher;

    /** @var list<array{url:string,payload:string,headers:list<string>}> */
    private array $calls = [];

    /** @var array{ok:bool,status:int,error:string,retryable:bool} */
    private array $nextResult = ['ok' => true, 'status' => 200, 'error' => '', 'retryable' => false];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->kernel->container->get(WebhookRepository::class);
        $this->dispatcher = $this->fakeDispatcher();
    }

    private function fakeDispatcher(): WebhookDispatcher
    {
        $sender = function (string $url, string $payload, array $headers): array {
            $this->calls[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
            return $this->nextResult;
        };
        return new WebhookDispatcher($this->repo, $this->kernel->container->get(LoggerInterface::class), $sender);
    }

    private function subscribe(string $url, string $secret, array $events): int
    {
        return $this->repo->create($url, $secret, $events, null);
    }

    public function testDispatchFansOutToMatchingAndWildcard(): void
    {
        $this->subscribe('http://a.example/hook', 'whsec_a', ['post.published']);
        $this->subscribe('http://b.example/hook', 'whsec_b', ['*']);
        $this->subscribe('http://c.example/hook', 'whsec_c', ['comment.created']);

        $ids = $this->dispatcher->dispatch('post.published', ['slug' => 'hello', 'title' => '你好']);
        self::assertCount(2, $ids, '仅匹配的 + 通配订阅收到');
        self::assertCount(2, $this->calls);
        foreach ($ids as $id) {
            self::assertSame('delivered', $this->repo->findDelivery($id)['status']);
        }
    }

    public function testPayloadShapeAndSignatureHeaders(): void
    {
        $secret = 'whsec_sign';
        $this->subscribe('http://a.example/hook', $secret, ['post.published']);
        $ids = $this->dispatcher->dispatch('post.published', ['slug' => 'x', 'title' => '标题'], 'evt_fixed');

        $call = $this->calls[0];
        $body = json_decode($call['payload'], true);
        self::assertSame('evt_fixed', $body['id']);
        self::assertSame('post.published', $body['event']);
        self::assertArrayHasKey('occurredAt', $body);
        self::assertSame(['slug' => 'x', 'title' => '标题'], $body['data']);

        $headers = implode("\r\n", $call['headers']);
        self::assertStringContainsString('X-PenBlog-Event: post.published', $headers);
        self::assertStringContainsString('X-PenBlog-Delivery: ' . $ids[0], $headers);
        self::assertStringContainsString(
            'X-PenBlog-Signature: sha256=' . hash_hmac('sha256', $call['payload'], $secret),
            $headers,
        );
        self::assertStringNotContainsString('Content-Length', $headers, 'PHP stream 禁手动 Content-Length,由包装器生成');
    }

    public function testClientErrorDeadLettersImmediately(): void
    {
        $this->nextResult = ['ok' => false, 'status' => 403, 'error' => 'http_403', 'retryable' => false];
        $this->subscribe('http://a.example/hook', 's', ['*']);
        $ids = $this->dispatcher->dispatch('webhook.test', []);
        $row = $this->repo->findDelivery($ids[0]);
        self::assertSame('dead_lettered', $row['status'], '4xx 不可重试直接死信');
        self::assertNull($row['next_retry_at']);
        self::assertSame(403, (int) $row['response_code']);
    }

    public function testServerErrorBacksOffToFailed(): void
    {
        $this->nextResult = ['ok' => false, 'status' => 500, 'error' => 'http_500', 'retryable' => true];
        $this->subscribe('http://a.example/hook', 's', ['*']);
        $ids = $this->dispatcher->dispatch('webhook.test', []);
        $row = $this->repo->findDelivery($ids[0]);
        self::assertSame('failed', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['next_retry_at'], '退避窗口内不立即重试');
        self::assertGreaterThan(gmdate('Y-m-d\TH:i:s\Z'), (string) $row['next_retry_at']);
    }

    public function testMaxAttemptsExhaustsToDeadLetter(): void
    {
        $this->nextResult = ['ok' => false, 'status' => 503, 'error' => 'http_503', 'retryable' => true];
        $hookId = $this->subscribe('http://a.example/hook', 's', ['*']);
        $id = $this->repo->insertDelivery($hookId, 'webhook.test', 'evt_x', '{}');
        $this->repo->updateDelivery($id, ['status' => 'failed', 'attempts' => 4, 'next_retry_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);

        self::assertSame(1, $this->dispatcher->retryDue());
        $row = $this->repo->findDelivery($id);
        self::assertSame('dead_lettered', $row['status'], '第 5 次仍失败 → 死信');
        self::assertSame(5, (int) $row['attempts']);
    }

    public function testRetryDueSuccessTurnsDelivered(): void
    {
        $hookId = $this->subscribe('http://a.example/hook', 's', ['*']);
        $id = $this->repo->insertDelivery($hookId, 'webhook.test', 'evt_y', '{"event":"webhook.test"}');
        $this->repo->updateDelivery($id, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);

        self::assertSame(1, $this->dispatcher->retryDue());
        $row = $this->repo->findDelivery($id);
        self::assertSame('delivered', $row['status']);
        self::assertNull($row['next_retry_at']);
    }

    public function testRetryDueDropsGoneOrInactiveWebhook(): void
    {
        $hookId = $this->subscribe('http://a.example/hook', 's', ['*']);
        $id = $this->repo->insertDelivery($hookId, 'webhook.test', 'evt_z', '{}');
        $this->repo->updateDelivery($id, ['status' => 'failed', 'attempts' => 1, 'next_retry_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]);
        $this->repo->update($hookId, ['active' => false]);

        self::assertSame(1, $this->dispatcher->retryDue());
        $row = $this->repo->findDelivery($id);
        self::assertSame('dead_lettered', $row['status']);
        self::assertSame('webhook_gone_or_inactive', $row['error']);
        self::assertCount(0, $this->calls, '停用订阅不再外呼');
    }

    public function testTestEndpointDispatchesWebhookTestEvent(): void
    {
        $hookId = $this->subscribe('http://a.example/hook', 's', ['post.published']);
        $id = $this->dispatcher->test($hookId);
        $row = $this->repo->findDelivery($id);
        self::assertSame('webhook.test', $row['event'], 'test 不受 events 订阅限制');
        self::assertSame('delivered', $row['status']);
    }

    public function testEventBusFanoutThroughKernelListeners(): void
    {
        $this->subscribe('http://a.example/hook', 'whsec_bus', ['post.published']);
        $this->subscribe('http://b.example/hook', 'whsec_bus2', ['comment.created']);
        // 覆盖容器单例,让 boot 时注册的监听器走 fake sender
        $this->kernel->container->instance(WebhookDispatcher::class, $this->dispatcher);

        $this->kernel->events->dispatch(new PostPublished('hello-world', 'Hello', 'admin'));
        self::assertCount(1, $this->calls, 'PostPublished 扇出到 post.published 订阅');
        $body = json_decode($this->calls[0]['payload'], true);
        self::assertSame('post.published', $body['event']);
        self::assertSame('hello-world', $body['data']['slug']);

        $this->calls = [];
        $this->kernel->events->dispatch(new CommentCreated(
            commentId: 7, postSlug: 'hello-world', postTitle: 'Hello',
            postAuthorUsername: 'admin', authorName: '访客', userId: null,
            status: 'pending', excerpt: 'hi',
        ));
        self::assertCount(1, $this->calls);
        self::assertSame('comment.created', json_decode($this->calls[0]['payload'], true)['event']);
    }

    public function testEncodePayloadUnicodeSafe(): void
    {
        $json = WebhookDispatcher::encodePayload('evt_1', 'post.published', ['title' => '中文 & /斜杠']);
        self::assertStringContainsString('中文', $json);
        self::assertStringContainsString('/斜杠', $json);
        self::assertStringNotContainsString('\\u', $json);
    }
}
