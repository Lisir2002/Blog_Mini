<?php

declare(strict_types=1);

namespace PenBlog\Notification;

/** 渠道投递结果(05 §6)。retryable=true 才进入退避重试。 */
final class DeliveryResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $error = '',
        public readonly bool $retryable = false,
    ) {
    }

    public static function success(string $note = ''): self
    {
        return new self(true, $note);
    }

    public static function failure(string $error, bool $retryable = true): self
    {
        return new self(false, $error, $retryable);
    }
}
