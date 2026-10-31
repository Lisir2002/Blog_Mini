<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

/**
 * 插件入口契约(04 §2):生命周期回调由 PluginManager 调用。
 * onActivate 抛异常 → 插件标记 failed,不影响内核与其他插件。
 */
interface PluginInterface
{
    public function onActivate(PluginContext $ctx): void;

    public function onDeactivate(): void;

    public function onUninstall(): void;
}
