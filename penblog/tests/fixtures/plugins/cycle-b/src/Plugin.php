<?php

declare(strict_types=1);

namespace CycleB;

use PenBlog\Plugin\PluginContext;
use PenBlog\Plugin\PluginInterface;

final class Plugin implements PluginInterface
{
    public function onActivate(PluginContext $ctx): void
    {
    }

    public function onDeactivate(): void
    {
    }

    public function onUninstall(): void
    {
    }
}
