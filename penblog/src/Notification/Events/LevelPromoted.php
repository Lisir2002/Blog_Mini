<?php

declare(strict_types=1);

namespace PenBlog\Notification\Events;

/** 领域事件(09 §2):经验驱动等级自动晋升(L0 永不自动,只升不降)。 */
final class LevelPromoted
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly int $oldLevel,
        public readonly int $newLevel,
        public readonly int $xp,
    ) {
    }
}
