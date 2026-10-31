<?php

declare(strict_types=1);

namespace Broken;

use PenBlog\Plugin\PluginContext;
use PenBlog\Plugin\PluginInterface;

final class Plugin implements PluginInterface
{
    public function onActivate(PluginContext $ctx): void
    {
        throw new \RuntimeException('boom');
    }

    public function onDeactivate(): void
    {
    }

    public function onUninstall(): void
    {
    }
}
