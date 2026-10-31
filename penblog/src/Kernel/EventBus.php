<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * 双轨事件总线(见 02 §2):
 * - PSR-14 dispatch:按事件类名广播,监听者只读消费。
 * - doAction/applyFilters:WordPress 式钩子;Filter 必须返回值,链式传递。
 * priority 默认 10,越小越先执行;同 priority 按注册顺序。
 */
final class EventBus implements EventDispatcherInterface
{
    /** @var array<string, list<array{priority:int, seq:int, handler:callable}>> */
    private array $listeners = [];

    private int $seq = 0;

    public function listen(string $key, callable $handler, int $priority = 10): void
    {
        $this->listeners[$key][] = ['priority' => $priority, 'seq' => $this->seq++, 'handler' => $handler];
    }

    public function dispatch(object $event): object
    {
        foreach ($this->sorted($event::class) as $handler) {
            $handler($event);
        }
        return $event;
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        foreach ($this->sorted($hook) as $handler) {
            $handler(...$args);
        }
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->sorted($hook) as $handler) {
            $result = $handler($value, ...$args);
            // Filter 监听者必须返回值;返回 null 视为未修改(开发模式告警)。
            if ($result === null) {
                trigger_error("Filter hook '{$hook}' listener returned null; value unchanged.", E_USER_WARNING);
            } else {
                $value = $result;
            }
        }
        return $value;
    }

    /** @return list<callable> */
    private function sorted(string $key): array
    {
        $listeners = $this->listeners[$key] ?? [];
        usort($listeners, static fn (array $a, array $b): int => $a['priority'] === $b['priority']
            ? $a['seq'] <=> $b['seq']
            : $a['priority'] <=> $b['priority']);
        return array_map(static fn (array $l): callable => $l['handler'], $listeners);
    }
}
