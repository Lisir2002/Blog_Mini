<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

use PenBlog\Kernel\EventBus;

/**
 * 事件总线代理(04 §2 可撤销硬约束):listen 句柄登记到 PluginManager,
 * deactivate 时统一 unlisten。dispatch/doAction/applyFilters 原样透传。
 */
final class ScopedEventBus extends EventBus
{
    public function __construct(
        private readonly EventBus $inner,
        private readonly PluginManager $manager,
        private readonly string $plugin,
    ) {
        // 不调用 parent::__construct():本实例仅作代理,监听表由 $inner 持有
    }

    public function listen(string $key, callable $handler, int $priority = 10): int
    {
        $id = $this->inner->listen($key, $handler, $priority);
        $this->manager->trackEvent($this->plugin, $id);
        return $id;
    }

    public function unlisten(int $handle): void
    {
        $this->inner->unlisten($handle);
    }

    public function dispatch(object $event): object
    {
        return $this->inner->dispatch($event);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        $this->inner->doAction($hook, ...$args);
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $this->inner->applyFilters($hook, $value, ...$args);
    }
}
