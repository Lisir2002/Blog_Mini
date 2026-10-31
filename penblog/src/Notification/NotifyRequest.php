<?php

declare(strict_types=1);

namespace PenBlog\Notification;

/**
 * 05 §3 接入层请求:幂等 eventId + 业务去重 dedupKey + 分类 + 收件人表达式。
 * recipient:int userId | 'role:admin' | 'role:manager' | 'broadcast'。
 */
final class NotifyRequest
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventId,
        public readonly string $category,
        public readonly string|int $recipient,
        public readonly string $templateKey,
        public readonly array $payload,
        public readonly ?string $dedupKey = null,
        public readonly string $link = '',
        public readonly string $importance = 'normal',
        public readonly string $locale = 'zh-CN',
    ) {
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x70); // v7 近似:时间序在高 4 位不可得,退 v4 形态
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
