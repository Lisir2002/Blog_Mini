<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit;

use PenBlog\Kernel\Container;
use PenBlog\Kernel\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ContainerTest extends TestCase
{
    public function testSingletonResolvesOnce(): void
    {
        $c = new Container();
        $calls = 0;
        $c->singleton('clock', static function () use (&$calls): \stdClass {
            $calls++;
            return new \stdClass();
        });

        $a = $c->get('clock');
        $b = $c->get('clock');
        self::assertSame($a, $b);
        self::assertSame(1, $calls);
    }

    public function testBindResolvesFreshEachTime(): void
    {
        $c = new Container();
        $c->bind('obj', static fn (): \stdClass => new \stdClass());
        self::assertNotSame($c->get('obj'), $c->get('obj'));
    }

    public function testFactoryReceivesContainer(): void
    {
        $c = new Container();
        $c->instance('dep', new \stdClass());
        $c->singleton('svc', static fn (ContainerInterface $cc): \stdClass => (object) ['dep' => $cc->get('dep')]);
        self::assertObjectHasProperty('dep', $c->get('svc'));
    }

    public function testUnknownServiceThrows(): void
    {
        $c = new Container();
        $this->expectException(NotFoundException::class);
        $c->get('nope');
    }

    public function testHas(): void
    {
        $c = new Container();
        self::assertFalse($c->has('x'));
        $c->instance('x', new \stdClass());
        self::assertTrue($c->has('x'));
    }
}
