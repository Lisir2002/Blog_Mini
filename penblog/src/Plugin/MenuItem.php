<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

/**
 * MenuItem 扩展点(04 §4):后台侧栏/前台导航追加项。
 */
final class MenuItem
{
    public const AREAS = ['admin', 'nav'];

    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly string $area = 'admin',
        public readonly int $priority = 100,
    ) {
        if ($label === '' || mb_strlen($label) > 64) {
            throw new \InvalidArgumentException('MenuItem label must be 1-64 chars');
        }
        if (!in_array($area, self::AREAS, true)) {
            throw new \InvalidArgumentException("MenuItem area must be one of: " . implode(', ', self::AREAS));
        }
        if (!preg_match('#^(/[a-z0-9/_-]*|https?://\S+)$#i', $url)) {
            throw new \InvalidArgumentException('MenuItem url must be a site path or absolute http(s) URL');
        }
    }
}
