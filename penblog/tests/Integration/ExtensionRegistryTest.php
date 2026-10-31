<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Kernel\Extension;
use PenBlog\Kernel\ListQuery;
use PenBlog\Kernel\Metadata;

final class Post extends Extension
{
}

final class ExtensionRegistryTest extends KernelTestCase
{
    public function testRegisterAssignsUidAndTimestamps(): void
    {
        $registry = $this->kernel->extensions();
        $post = new Post(Metadata::generate('hello-world', 'Post'));
        $post->spec = ['title' => 'Hello'];

        $saved = $registry->register($post);
        self::assertNotSame('', $saved->metadata->uid);
        self::assertNotNull($saved->metadata->creationTimestamp);
        self::assertSame(1, $saved->metadata->resourceVersion);
    }

    public function testReRegisterIncrementsResourceVersionAndKeepsUid(): void
    {
        $registry = $this->kernel->extensions();
        $registry->registerType('Post', Post::class);

        $first = $registry->register(new Post(Metadata::generate('p1', 'Post'), ['v' => 1]));
        $second = $registry->register(new Post(Metadata::generate('p1', 'Post'), ['v' => 2]));

        $found = $registry->find('Post', 'p1');
        self::assertInstanceOf(Post::class, $found);
        self::assertSame($first->metadata->uid, $found->metadata->uid);
        self::assertSame(2, $found->metadata->resourceVersion);
        self::assertSame(['v' => 2], $found->spec);
    }

    public function testQueryPaginatesAndSorts(): void
    {
        $registry = $this->kernel->extensions();
        for ($i = 1; $i <= 5; $i++) {
            $e = new Extension(Metadata::generate("item-{$i}", 'Thing'));
            // 制造可区分的创建时间
            $e->metadata = new Metadata(
                name: "item-{$i}", uid: $e->metadata->uid, kind: 'Thing',
                creationTimestamp: sprintf('2026-01-0%dT00:00:00Z', $i),
                updateTimestamp: sprintf('2026-01-0%dT00:00:00Z', $i),
            );
            $registry->register($e);
        }

        $page = $registry->query('Thing', ListQuery::fromParams(['size' => 2, 'sort' => ['name']]));
        self::assertSame(5, $page->total);
        self::assertCount(2, $page->items);
        self::assertSame('item-1', $page->items[0]->metadata->name);
        self::assertNotNull($page->nextCursor);

        $page2 = $registry->query('Thing', ListQuery::fromParams(['cursor' => $page->nextCursor, 'size' => 2, 'sort' => ['name']]));
        self::assertSame('item-3', $page2->items[0]->metadata->name);
    }

    public function testFilterByName(): void
    {
        $registry = $this->kernel->extensions();
        $registry->register(new Extension(Metadata::generate('alpha', 'Thing')));
        $registry->register(new Extension(Metadata::generate('beta', 'Thing')));

        $page = $registry->query('Thing', ListQuery::fromParams(['filter' => ['name' => 'beta']]));
        self::assertSame(1, $page->total);
        self::assertSame('beta', $page->items[0]->metadata->name);
    }

    public function testDelete(): void
    {
        $registry = $this->kernel->extensions();
        $registry->register(new Extension(Metadata::generate('gone', 'Thing')));
        self::assertTrue($registry->delete('Thing', 'gone'));
        self::assertNull($registry->find('Thing', 'gone'));
    }
}
