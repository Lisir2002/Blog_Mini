<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

/**
 * 插件调用了 manifest 未声明的能力(04 §3/§5 红线②)。
 */
final class PermissionDenied extends \RuntimeException
{
    public static function for(string $plugin, string $permission): self
    {
        return new self("Plugin '{$plugin}' has not declared permission '{$permission}' in plugin.json");
    }
}
