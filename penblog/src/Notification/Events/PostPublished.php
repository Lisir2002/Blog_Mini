<?php

declare(strict_types=1);

namespace PenBlog\Notification\Events;

/** 领域事件(05 §3 内核钩子):文章状态首次进入 published。 */
final class PostPublished
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $authorUsername,
    ) {
    }
}
