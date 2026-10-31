<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\UserRepository;

/**
 * 09 §1 四级权限矩阵:L0 全管,L1 管他人内容,L2 只发自己,L3 只评论,匿名只读已发布。
 */
final class RbacMatrixTest extends KernelTestCase
{
    /** @var array<string, array<string, string>> */
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $users = $this->kernel->container->get(UserRepository::class);
        $users->create('editor1', 'e1@example.com', 'password123', 1);
        $users->create('author1', 'a1@example.com', 'password123', 2);
        $users->create('author2', 'a2@example.com', 'password123', 2);
        $users->create('sub1', 's1@example.com', 'password123', 3);
        foreach (['admin', 'editor1', 'author1', 'author2', 'sub1'] as $u) {
            $this->cookies[$u] = $this->login($u, 'password123');
        }
        // author1 的一篇已发布文章 + 一篇草稿
        $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->cookies['author1'], [
            'title' => '作者文章', 'status' => 'published',
        ]));
    }

    public function testAnonymousReadsPublishedOnly(): void
    {
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/api/v1/posts/作者文章'))->getStatusCode());
        self::assertSame(403, $this->kernel->handle($this->request('POST', '/api/v1/posts', ['title' => 'x']))->getStatusCode());
        self::assertSame(403, $this->kernel->handle($this->request('POST', '/api/v1/posts/作者文章/comments', ['body' => 'hi']))->getStatusCode());
    }

    public function testSubscriberCanCommentButNotPost(): void
    {
        $sub = $this->cookies['sub1'];
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts/作者文章/comments', $sub, ['body' => '赞']));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(403, $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $sub, ['title' => 'x']))->getStatusCode());
        self::assertSame(403, $this->kernel->handle($this->authRequest('PUT', '/api/v1/users/admin/level', $sub, ['level' => 2]))->getStatusCode());
    }

    public function testAuthorWritesOwnOnly(): void
    {
        $a1 = $this->cookies['author1'];
        $a2 = $this->cookies['author2'];
        // 本人可改自己的
        self::assertSame(200, $this->kernel->handle($this->authRequest('PUT', '/api/v1/posts/作者文章', $a1, ['title' => '作者文章改']))->getStatusCode());
        // 他人不可改(slug 不随标题变化)
        self::assertSame(403, $this->kernel->handle($this->authRequest('PUT', '/api/v1/posts/作者文章', $a2, ['title' => '劫持']))->getStatusCode());
        self::assertSame(403, $this->kernel->handle($this->authRequest('DELETE', '/api/v1/posts/作者文章', $a2))->getStatusCode());
        // 作者无用户/页面管理权
        self::assertSame(403, $this->kernel->handle($this->authRequest('POST', '/api/v1/pages', $a1, ['title' => 'x']))->getStatusCode());
    }

    public function testEditorManagesContentNotSystem(): void
    {
        $ed = $this->cookies['editor1'];
        // 编辑可改他人文章(作者归属不变)
        $response = $this->kernel->handle($this->authRequest('PUT', '/api/v1/posts/作者文章', $ed, ['title' => '编辑改过']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('author1', json_decode((string) $response->getBody(), true)['spec']['author']);
        // 编辑可建页面
        self::assertSame(201, $this->kernel->handle($this->authRequest('POST', '/api/v1/pages', $ed, ['title' => '关于']))->getStatusCode());
        // 编辑不能调级(无 system:manage)
        self::assertSame(403, $this->kernel->handle($this->authRequest('PUT', '/api/v1/users/sub1/level', $ed, ['level' => 2]))->getStatusCode());
    }

    public function testAdminDoesEverything(): void
    {
        $ad = $this->cookies['admin'];
        self::assertSame(200, $this->kernel->handle($this->authRequest('PUT', '/api/v1/users/sub1/level', $ad, ['level' => 2]))->getStatusCode());
        self::assertSame(201, $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $ad, ['title' => '管理员文']))->getStatusCode());
        self::assertSame(201, $this->kernel->handle($this->authRequest('POST', '/api/v1/categories', $ad, ['name' => '新闻']))->getStatusCode());
    }

    public function testLevelChangeTakesEffectImmediately(): void
    {
        $ad = $this->cookies['admin'];
        $this->kernel->handle($this->authRequest('PUT', '/api/v1/users/sub1/level', $ad, ['level' => 2]));
        // sub1 旧会话即时获得 L2 权限(等级读自用户行)
        $response = $this->kernel->handle($this->authRequest('POST', '/api/v1/posts', $this->cookies['sub1'], ['title' => '升级后发文']));
        self::assertSame(201, $response->getStatusCode());
    }
}
