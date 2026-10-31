<?php

declare(strict_types=1);

namespace PenBlog\Webhook;

use PenBlog\Kernel\Kernel;
use PenBlog\Notification\Events\CommentCreated;
use PenBlog\Notification\Events\PostPublished;
use Psr\Log\LoggerInterface;

/**
 * 06 §8 Webhook 出站:领域事件 → 匹配的订阅扇出投递。
 * 事件体 {id, event, occurredAt, data};HMAC-SHA256 签名、指数退避、死信(与 05 §6 同语义)。
 * $sender 可注入(测试),签名:fn(string $url, string $payload, list<string> $headers): array。
 */
final class WebhookDispatcher
{
    /** 可订阅事件白名单(06 §8) */
    public const EVENTS = ['post.published', 'comment.created', 'webhook.test'];

    private const BACKOFF = [60, 300, 1500, 7200, 28800];
    private const MAX_ATTEMPTS = 5;

    /** @var callable(string, string, list<string>): array{ok:bool, status:int, error:string, retryable:bool} */
    private $sender;

    public function __construct(
        private readonly WebhookRepository $repo,
        private readonly LoggerInterface $logger,
        ?callable $sender = null,
    ) {
        $this->sender = $sender ?? self::httpPost(...);
    }

    public static function register(Kernel $kernel): void
    {
        $events = $kernel->events;
        $get = static fn (): self => $kernel->container->get(self::class);
        $safe = static function (callable $fn) use ($kernel): void {
            // 投递失败绝不影响主流程(与 NotificationService 同策略)
            try {
                $fn();
            } catch (\Throwable $e) {
                $kernel->logger()->error('webhook fanout failed: ' . $e->getMessage());
            }
        };
        $events->listen(PostPublished::class, static function (PostPublished $e) use ($get, $safe): void {
            $safe(static fn () => $get()->dispatch('post.published', [
                'slug' => $e->slug,
                'title' => $e->title,
                'author' => $e->authorUsername,
                'link' => '/posts/' . $e->slug,
            ]));
        });
        $events->listen(CommentCreated::class, static function (CommentCreated $e) use ($get, $safe): void {
            $safe(static fn () => $get()->dispatch('comment.created', [
                'commentId' => $e->commentId,
                'postSlug' => $e->postSlug,
                'postTitle' => $e->postTitle,
                'authorName' => $e->authorName,
                'status' => $e->status,
                'excerpt' => $e->excerpt,
                'link' => '/posts/' . $e->postSlug . '#comment-' . $e->commentId,
            ]));
        });
    }

    /** @param array<string, mixed> $data @return list<int> 投递日志行 id */
    public function dispatch(string $event, array $data, ?string $eventId = null): array
    {
        $eventId ??= 'evt_' . bin2hex(random_bytes(8));
        $payload = self::encodePayload($eventId, $event, $data);
        $ids = [];
        foreach ($this->repo->activeFor($event) as $hook) {
            $id = $this->repo->insertDelivery((int) $hook['id'], $event, $eventId, $payload);
            $this->attempt($id, $hook);
            $ids[] = $id;
        }
        return $ids;
    }

    /** 向指定 Webhook 发送 webhook.test 事件(06 §8 测试端点)。 @return int 投递日志行 id */
    public function test(int $webhookId): int
    {
        $hook = $this->repo->find($webhookId);
        if ($hook === null) {
            throw \PenBlog\Api\ApiException::notFound('Webhook');
        }
        $eventId = 'evt_' . bin2hex(random_bytes(8));
        $payload = self::encodePayload($eventId, 'webhook.test', ['message' => 'PenBlog webhook 测试事件']);
        $id = $this->repo->insertDelivery($webhookId, 'webhook.test', $eventId, $payload);
        $this->attempt($id, $hook);
        return $id;
    }

    /** 重试到期的 failed 投递。 @return int 处理条数 */
    public function retryDue(): int
    {
        $done = 0;
        foreach ($this->repo->dueDeliveries(gmdate('Y-m-d\TH:i:s\Z')) as $row) {
            $hook = $this->repo->find((int) $row['webhook_id']);
            if ($hook === null || !(bool) $hook['active']) {
                $this->repo->updateDelivery((int) $row['id'], ['status' => 'dead_lettered', 'next_retry_at' => null, 'error' => 'webhook_gone_or_inactive']);
            } else {
                $this->attempt((int) $row['id'], $hook);
            }
            $done++;
        }
        return $done;
    }

    /** @param array<string, mixed> $hook */
    private function attempt(int $deliveryId, array $hook): void
    {
        $row = $this->repo->findDelivery($deliveryId);
        if ($row === null) {
            return;
        }
        $attempts = (int) $row['attempts'] + 1;
        $result = ($this->sender)((string) $hook['url'], (string) $row['payload'], $this->headers($row, $hook, (string) $hook['secret']));
        $fields = [
            'attempts' => $attempts,
            'response_code' => $result['status'],
            'error' => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 255),
        ];
        if ($result['ok']) {
            $fields['status'] = 'delivered';
            $fields['next_retry_at'] = null;
        } elseif (!$result['retryable'] || $attempts >= self::MAX_ATTEMPTS) {
            $fields['status'] = 'dead_lettered';
            $fields['next_retry_at'] = null;
        } else {
            $fields['status'] = 'failed';
            $fields['next_retry_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + self::BACKOFF[$attempts - 1]);
        }
        $this->repo->updateDelivery($deliveryId, $fields);
        if (!$result['ok'] && $fields['status'] === 'dead_lettered') {
            $this->logger->warning("webhook delivery {$deliveryId} dead-lettered: {$result['error']}");
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $hook
     * @return list<string>
     */
    private function headers(array $row, array $hook, string $secret): array
    {
        return [
            'Content-Type: application/json',
            // 不设 Content-Length:PHP stream 上下文禁止手动指定(会被丢弃并导致请求挂起),由包装器自动生成
            'X-PenBlog-Event: ' . (string) $row['event'],
            'X-PenBlog-Delivery: ' . (string) $row['id'],
            'X-PenBlog-Signature: sha256=' . hash_hmac('sha256', (string) $row['payload'], $secret),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function encodePayload(string $eventId, string $event, array $data): string
    {
        return json_encode([
            'id' => $eventId,
            'event' => $event,
            'occurredAt' => gmdate('c'),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** 默认真实 HTTP 投递:5s 超时,4xx 不可重试,5xx/传输错误可重试。 @return array{ok:bool, status:int, error:string, retryable:bool} */
    public static function httpPost(string $url, string $payload, array $headers): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'transport_error', 'retryable' => true];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'error' => '', 'retryable' => false];
        }
        return ['ok' => false, 'status' => $status, 'error' => "http_{$status}", 'retryable' => $status >= 500 || $status === 429];
    }
}
