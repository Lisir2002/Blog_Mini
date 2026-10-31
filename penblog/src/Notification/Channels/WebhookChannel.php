<?php

declare(strict_types=1);

namespace PenBlog\Notification\Channels;

use PenBlog\Auth\User;
use PenBlog\Kernel\OptionsStore;
use PenBlog\Notification\ChannelAdapter;
use PenBlog\Notification\DeliveryResult;

/**
 * 05 §6 Webhook 渠道:per-user 配置(options notify.webhook.url.{userId} /
 * notify.webhook.secret.{userId})。未配置视为跳过(成功,不重试)。
 * 请求带 X-PenBlog-Signature: sha256=HMAC(body, secret)。
 */
final class WebhookChannel implements ChannelAdapter
{
    public function __construct(private readonly OptionsStore $options)
    {
    }

    public function name(): string
    {
        return 'webhook';
    }

    public function send(array $rendered, User $to): DeliveryResult
    {
        $url = (string) $this->options->get('notify.webhook.url.' . $to->id(), '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return DeliveryResult::success('skipped:not_configured');
        }
        $secret = (string) $this->options->get('notify.webhook.secret.' . $to->id(), '');
        $payload = json_encode([
            'title' => $rendered['title'],
            'body' => strip_tags($rendered['body']),
            'link' => $rendered['link'],
            'to' => $to->username(),
            'sentAt' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen((string) $payload),
            'X-PenBlog-Signature: sha256=' . hash_hmac('sha256', (string) $payload, $secret),
        ];
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'body' => (string) $payload,
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return DeliveryResult::failure('transport_error');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status >= 200 && $status < 300
            ? DeliveryResult::success()
            : DeliveryResult::failure("http_{$status}", $status >= 500);
    }
}
