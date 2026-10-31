<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

final class ContentApiTest extends KernelTestCase
{
    public function testCreateReadUpdateDeletePost(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, [
            'title' => 'Hello World 你好',
            'bodyMd' => '正文内容',
            'tags' => ['技术', '生活'],
            'categories' => ['随笔'],
            'status' => 'published',
        ]));
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('hello-world-你好', $data['metadata']['name']);
        self::assertSame('published', $data['spec']['status']);
        self::assertNotEmpty($data['spec']['publishedAt']);
        self::assertSame(['技术', '生活'], $data['spec']['tags']);

        // 匿名可读已发布
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts/hello-world-你好'));
        self::assertSame(200, $response->getStatusCode());

        // 列表可见
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts'));
        $list = json_decode((string) $response->getBody(), true);
        self::assertSame(1, $list['total']);

        // 更新(带正确 resourceVersion)
        $rv = $data['metadata']['resourceVersion'];
        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/posts/hello-world-你好', $cookies, [
            'title' => '改名了',
            'metadata' => ['resourceVersion' => $rv],
        ]));
        self::assertSame(200, $response->getStatusCode());
        $updated = json_decode((string) $response->getBody(), true);
        self::assertSame('改名了', $updated['spec']['title']);
        self::assertSame('hello-world-你好', $updated['metadata']['name'], 'slug 不随标题变化');

        // 删除后 404
        $response = $this->kernel->handle($this->authRequest('DELETE', '/api/v1/posts/hello-world-你好', $cookies));
        self::assertSame(200, $response->getStatusCode());
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts/hello-world-你好'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testSlugConflictAppendsSuffix(): void
    {
        $cookies = $this->login('admin', 'password123');
        foreach (['同名文章', '同名文章'] as $_) {
            $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, ['title' => '同名文章']));
            self::assertSame(201, $response->getStatusCode());
        }
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts', null, [], $cookies));
        $names = array_column(array_column(json_decode((string) $response->getBody(), true)['items'], 'metadata'), 'name');
        self::assertContains('同名文章', $names);
        self::assertContains('同名文章-2', $names);
    }

    public function testDraftInvisibleToOthers(): void
    {
        $this->kernel->container->get(\PenBlog\Auth\UserRepository::class)
            ->create('author2', 'a2@example.com', 'password123', 2);
        $authorCookies = $this->login('author2', 'password123');

        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $authorCookies, [
            'title' => '草稿', 'status' => 'draft',
        ]));
        self::assertSame(201, $response->getStatusCode());

        // 作者本人可见
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts/草稿', null, [], $authorCookies));
        self::assertSame(200, $response->getStatusCode());
        // 匿名不可见
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts/草稿'));
        self::assertSame(404, $response->getStatusCode());
        // 他人(L3 订阅者)不可见
        $this->kernel->container->get(\PenBlog\Auth\UserRepository::class)
            ->create('sub2', 's2@example.com', 'password123', 3);
        $other = $this->login('sub2', 'password123');
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts/草稿', null, [], $other));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testPublishCustomMethod(): void
    {
        $cookies = $this->login('admin', 'password123');
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, ['title' => '待发']));
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts/待发:publish', $cookies));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('published', $data['spec']['status']);
        self::assertNotEmpty($data['spec']['publishedAt']);
    }

    public function testOptimisticConcurrencyConflict(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, ['title' => '并发']));
        $rv = json_decode((string) $response->getBody(), true)['metadata']['resourceVersion'];

        // 第一次更新成功
        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/posts/并发', $cookies, [
            'title' => '并发 A', 'metadata' => ['resourceVersion' => $rv],
        ]));
        self::assertSame(200, $response->getStatusCode());

        // 用旧 rv 再更新 → 409
        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/posts/并发', $cookies, [
            'title' => '并发 B', 'metadata' => ['resourceVersion' => $rv],
        ]));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('resource_version_conflict', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testTagsAndCategoriesAutoCreated(): void
    {
        $cookies = $this->login('admin', 'password123');
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $cookies, [
            'title' => '分类文', 'categories' => ['技术笔记'], 'tags' => ['PHP'], 'status' => 'published',
        ]));

        $cats = json_decode((string) $this->kernel->handle($this->request('GET', '/api/v1/categories'))->getBody(), true);
        self::assertSame('技术笔记', $cats['items'][0]['name']);
        self::assertSame(1, $cats['items'][0]['postCount']);

        // 按分类过滤
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts?filter[category]=技术笔记'));
        self::assertSame(1, json_decode((string) $response->getBody(), true)['total']);
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts?filter[tag]=不存在'));
        self::assertSame(0, json_decode((string) $response->getBody(), true)['total']);
    }

    public function testCommentsFlow(): void
    {
        $admin = $this->login('admin', 'password123');
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $admin, ['title' => '评论文', 'status' => 'published']));

        $this->kernel->container->get(\PenBlog\Auth\UserRepository::class)
            ->create('sub3', 's3@example.com', 'password123', 3);
        $sub = $this->login('sub3', 'password123');

        // 订阅者评论 → pending
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts/评论文/comments', $sub, ['body' => '赞!']));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('pending', json_decode((string) $response->getBody(), true)['comment']['status']);

        // 匿名只见 approved(空)
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts/评论文/comments'));
        self::assertCount(0, json_decode((string) $response->getBody(), true)['items']);

        // 管理员(L0 有 comment:moderate)评论 → approved,且可见 pending
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts/评论文/comments', $admin, ['body' => '官方回复']));
        self::assertSame('approved', json_decode((string) $response->getBody(), true)['comment']['status']);
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts/评论文/comments', null, [], $admin));
        self::assertCount(2, json_decode((string) $response->getBody(), true)['items']);
        $response = $this->kernel->handle($this->request('GET', '/api/v1/posts/评论文/comments'));
        self::assertCount(1, json_decode((string) $response->getBody(), true)['items']);
    }

    public function testPagesCrud(): void
    {
        $cookies = $this->login('admin', 'password123');
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/pages', $cookies, [
            'title' => '关于本站', 'bodyMd' => '介绍', 'status' => 'published',
        ]));
        self::assertSame(201, $response->getStatusCode());

        // 匿名可读已发布
        $response = $this->kernel->handle($this->request('GET', '/api/v1/pages/关于本站'));
        self::assertSame(200, $response->getStatusCode());

        // 草稿匿名不可见
        $this->kernel->handle($this->authRequest('POST', '/api/v1/pages', $cookies, ['title' => '秘密页']));
        $response = $this->kernel->handle($this->request('GET', '/api/v1/pages/秘密页'));
        self::assertSame(404, $response->getStatusCode());

        // 无 post:manage 的用户不能建页
        $this->kernel->container->get(\PenBlog\Auth\UserRepository::class)
            ->create('sub4', 's4@example.com', 'password123', 3);
        $sub = $this->login('sub4', 'password123');
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/pages', $sub, ['title' => 'x']));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAnonymousCannotCreatePost(): void
    {
        $response = $this->kernel->handle($this->request('POST', '/api/v1/posts', ['title' => 'x']));
        self::assertSame(403, $response->getStatusCode());
    }
}
