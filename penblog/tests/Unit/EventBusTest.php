<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit;

use PenBlog\Kernel\EventBus;
use PHPUnit\Framework\TestCase;

final class PostPublished
{
    public function __construct(public readonly string $slug)
    {
    }
}

final class EventBusTest extends TestCase
{
    public function testDispatchNotifiesByEventClass(): void
    {
        $bus = new EventBus();
        $seen = [];
        $bus->listen(PostPublished::class, static function (PostPublished $e) use (&$seen): void {
            $seen[] = $e->slug;
        });
        $bus->dispatch(new PostPublished('hello'));
        self::assertSame(['hello'], $seen);
    }

    public function testPriorityOrdersListeners(): void
    {
        $bus = new EventBus();
        $order = [];
        $bus->listen('hook', static function () use (&$order): void {
            $order[] = 'late';
        }, 20);
        $bus->listen('hook', static function () use (&$order): void {
            $order[] = 'early';
        }, 5);
        $bus->doAction('hook');
        self::assertSame(['early', 'late'], $order);
    }

    public function testSamePriorityKeepsRegistrationOrder(): void
    {
        $bus = new EventBus();
        $order = [];
        $bus->listen('hook', static function () use (&$order): void {
            $order[] = 'first';
        }, 5);
        $bus->listen('hook', static function () use (&$order): void {
            $order[] = 'second';
        }, 5);
        $bus->doAction('hook');
        self::assertSame(['first', 'second'], $order);
    }

    public function testApplyFiltersChainsValues(): void
    {
        $bus = new EventBus();
        $bus->listen('title_filter', static fn (string $v): string => $v . ' A', 10);
        $bus->listen('title_filter', static fn (string $v): string => $v . ' B', 20);
        self::assertSame('start A B', $bus->applyFilters('title_filter', 'start'));
    }

    public function testDoActionPassesArgs(): void
    {
        $bus = new EventBus();
        $captured = null;
        $bus->listen('post.published', static function (int $id, string $slug) use (&$captured): void {
            $captured = [$id, $slug];
        });
        $bus->doAction('post.published', 7, 'hello');
        self::assertSame([7, 'hello'], $captured);
    }
}
