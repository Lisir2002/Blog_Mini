<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit\Menu;

use PenBlog\Api\ApiException;
use PenBlog\Auth\Gate;
use PenBlog\Auth\UserRepository;
use PenBlog\Content\Page;
use PenBlog\Content\PostRepository;
use PenBlog\Kernel\Metadata;
use PenBlog\Menu\MenuManager;
use PenBlog\Tests\Integration\KernelTestCase;

final class MenuManagerTest extends KernelTestCase
{
    private MenuManager $menus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->menus = $this->kernel->menus();
    }

    /** @return array{0: Gate} */
    private function adminGate(): array
    {
        $user = $this->kernel->container->get(UserRepository::class)->findByUsername('admin');
        self::assertNotNull($user);
        return [new Gate($user, ['*'])];
    }

    public function testSaveRejectsInvalidLocation(): void
    {
        $this->expectException(ApiException::class);
        $this->menus->save('Bad Location', []);
    }

    public function testSaveRejectsInvalidType(): void
    {
        $this->expectException(ApiException::class);
        $this->menus->save('primary', [['label' => '坏类型', 'type' => 'widget', 'target' => 'x']]);
    }

    public function testSaveRejectsEmptyLabelAndTooLongLabel(): void
    {
        $this->expectException(ApiException::class);
        $this->menus->save('primary', [['label' => '', 'type' => 'url', 'target' => '/']]);
    }

    public function testSaveRejectsRelativeProtocolUrl(): void
    {
        $this->expectException(ApiException::class);
        $this->menus->save('primary', [['label' => '外链', 'type' => 'url', 'target' => 'javascript:alert(1)']]);
    }

    public function testSaveSortsItemsAndPersists(): void
    {
        $saved = $this->menus->save('primary', [
            ['label' => '页面', 'type' => 'page', 'target' => 'about', 'sort' => 2],
            ['label' => '首页', 'type' => 'url', 'target' => '/', 'sort' => 1],
            ['label' => '无排序', 'type' => 'url', 'target' => '/feed'],
        ]);
        self::assertSame('primary', $saved->metadata->name);
        $items = $saved->spec['items'];
        self::assertSame(['无排序', '首页', '页面'], array_column($items, 'label'), 'sort 升序且默认 0');
        self::assertSame(1, $saved->metadata->resourceVersion);

        // 二次保存:整表覆盖,resourceVersion 递增,uid 复用
        $again = $this->menus->save('primary', [['label' => '新', 'type' => 'url', 'target' => '/x']]);
        self::assertSame(2, $again->metadata->resourceVersion);
        self::assertSame($saved->metadata->uid, $again->metadata->uid);
        self::assertCount(1, $again->spec['items']);
    }

    public function testResolveSkipsDeadTargetsAndKeepsLiveOnes(): void
    {
        // 已发布文章 + 未发布文章 + 已发布页面
        /** @var Gate $gate */
        [$gate] = $this->adminGate();
        $posts = $this->kernel->container->get(PostRepository::class);
        $live = $posts->save(null, ['title' => 'Live Post', 'status' => 'published'], $gate);
        $posts->save(null, ['title' => 'Draft Post', 'status' => 'draft'], $gate);
        $this->kernel->extensions()->register(new Page(
            Metadata::generate('about', Page::KIND),
            ['title' => '关于', 'status' => 'published', 'bodyMd' => 'hi'],
        ));

        $this->menus->save('primary', [
            ['label' => '文章', 'type' => 'post', 'target' => $live->slug()],
            ['label' => '草稿', 'type' => 'post', 'target' => 'draft-post'],
            ['label' => '关于', 'type' => 'page', 'target' => 'about'],
            ['label' => '幽灵页', 'type' => 'page', 'target' => 'nope'],
            ['label' => '作者', 'type' => 'author', 'target' => 'admin'],
            ['label' => '外链', 'type' => 'url', 'target' => 'https://example.com'],
        ]);
        $resolved = $this->menus->resolve('primary');
        $hrefs = array_column($resolved, 'href');
        self::assertContains('/posts/' . $live->slug(), $hrefs);
        self::assertContains('/p/about', $hrefs);
        self::assertContains('/authors/admin', $hrefs);
        self::assertContains('https://example.com', $hrefs);
        self::assertNotContains('/posts/draft-post', $hrefs);
        self::assertNotContains('/p/nope', $hrefs);
        self::assertCount(4, $resolved);
    }

    public function testPrimaryFallsBackToHomeWhenUnsetOrDead(): void
    {
        self::assertSame([['label' => '首页', 'href' => '/']], $this->menus->resolve('primary'));
        $this->menus->save('primary', [['label' => '幽灵', 'type' => 'page', 'target' => 'gone']]);
        self::assertSame([['label' => '首页', 'href' => '/']], $this->menus->resolve('primary'), '全部失效仍回退默认');
        self::assertSame([], $this->menus->resolve('footer'), 'footer 无默认回退');
    }

    public function testDeleteRemovesMenu(): void
    {
        $this->menus->save('footer', [['label' => 'RSS', 'type' => 'url', 'target' => '/feed']]);
        self::assertNotNull($this->menus->get('footer'));
        self::assertTrue($this->menus->delete('footer'));
        self::assertNull($this->menus->get('footer'));
        self::assertFalse($this->menus->delete('footer'));
    }

    public function testAllListsMenusByLocation(): void
    {
        $this->menus->save('primary', [['label' => '首页', 'type' => 'url', 'target' => '/']]);
        $this->menus->save('footer', []);
        $all = $this->menus->all();
        self::assertSame(['footer', 'primary'], array_column($all, 'location'));
    }
}
