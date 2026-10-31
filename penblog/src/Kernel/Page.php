<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

/**
 * 分页结果(06 §4)。
 */
final class Page
{
    /**
     * @param list<Extension> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $size,
        public readonly int $total,
        public readonly ?string $nextCursor = null,
    ) {
    }
}
