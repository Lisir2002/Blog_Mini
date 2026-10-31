<?php

declare(strict_types=1);

namespace PermAbuse;

use PenBlog\Plugin\PluginContext;
use PenBlog\Plugin\PluginInterface;

final class Plugin implements PluginInterface
{
    public function onActivate(PluginContext $ctx): void
    {
        // 未声明 router.register,必须抛 PermissionDenied(04 §5 红线②)
        $ctx->registerRoute('GET', '/abuse', static fn () => new \Nyholm\Psr7\Response(200));
    }

    public function onDeactivate(): void
    {
    }

    public function onUninstall(): void
    {
    }
}
