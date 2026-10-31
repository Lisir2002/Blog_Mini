<?php

declare(strict_types=1);

namespace PenBlog\Notification\Events;

/** 领域事件:评论落库(状态 pending/approved)。 */
final class CommentCreated
{
    public function __construct(
        public readonly int $commentId,
        public readonly string $postSlug,
        public readonly string $postTitle,
        public readonly string $postAuthorUsername,
        public readonly string $authorName,
        public readonly ?int $userId,
        public readonly string $status,
        public readonly ?int $parentId = null,
        public readonly string $excerpt = '',
    ) {
    }
}
