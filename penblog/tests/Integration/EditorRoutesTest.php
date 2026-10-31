<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\UserRepository;
use PenBlog\Content\Post;

/**
 * Markdown 编辑器路由(11 §4):权限 gate / CSRF / 创建 / 编辑 / 乐观并发冲突回显 / 服务端预览。
 */
final class EditorRoutesTest extends KernelTestCase
{
    /** @return array<string, string> */
    private function admin(): array
    {
        return $this->login('admin', 'password123');
    }

    public function testNewRequiresAuth(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/admin/posts/new'));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/admin/login', $res->getHeaderLine('Location'));
    }

    public function testNewPageRendersEditor(): void
    {
        $res = $this->kernel->handle($this->authRequest('GET', '/admin/posts/new', $this->admin()));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        self::assertStringContainsString('id="editor-form"', $html);
        self::assertStringContainsString('id="md-body"', $html);
        self::assertStringContainsString('/assets/editor.js', $html);
    }

    /** 工具栏全量按钮(11 §3.1),每个均带 aria-label。 */
    public function testToolbarCoversFullButtonList(): void
    {
        $res = $this->kernel->handle($this->authRequest('GET', '/admin/posts/new', $this->admin()));
        $html = (string) $res->getBody();
        foreach (['bold', 'italic', 'strike', 'heading', 'list', 'olist', 'task', 'quote',
            'code', 'block', 'link', 'image', 'table', 'hr', 'footnote', 'break'] as $action) {
            self::assertStringContainsString('data-md="' . $action . '"', $html, "toolbar action {$action}");
        }
        preg_match_all('/<button[^>]*class="[^"]*tb-btn[^>]*>/', $html, $btns);
        self::assertNotEmpty($btns[0]);
        foreach ($btns[0] as $btn) {
            self::assertStringContainsString('aria-label', $btn, '每个图标按钮必须有 aria-label');
        }
    }

    public function testCreateDraftFromForm(): void
    {
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/posts/new', $this->admin(), [
            'title' => '编辑器测试',
            'slug' => 'editor-test',
            'bodyMd' => "# 你好\n\n**粗** 与 |表格|演示|",
            'status' => 'draft',
            'categories' => '技术',
            'tags' => 'php, gfm',
        ]));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/admin/posts/editor-test/edit?saved=1', $res->getHeaderLine('Location'));

        $post = $this->kernel->extensions()->find(Post::KIND, 'editor-test');
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('draft', $post->status());
        self::assertSame(['技术'], $post->categories());
        self::assertSame(['php', 'gfm'], $post->tags());
        self::assertSame('admin', $post->author());
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $cookies = $this->admin();
        $res = $this->kernel->handle($this->request('POST', '/admin/posts/new', [
            'title' => '无 CSRF', 'bodyMd' => 'x',
        ], [], $cookies));
        self::assertSame(403, $res->getStatusCode());
        self::assertNull($this->kernel->extensions()->find(Post::KIND, '无-csrf'));
    }

    public function testEditPageShowsValuesAndResourceVersion(): void
    {
        $admin = $this->admin();
        $this->kernel->handle($this->authRequest('POST', '/admin/posts/new', $admin, [
            'title' => '待编辑', 'slug' => 'to-edit', 'bodyMd' => '原正文', 'status' => 'draft',
        ]));
        $res = $this->kernel->handle($this->authRequest('GET', '/admin/posts/to-edit/edit', $admin));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        self::assertStringContainsString('原正文', $html);
        self::assertStringContainsString('name="resourceVersion"', $html);
    }

    public function testEditSaveWithCorrectResourceVersion(): void
    {
        $admin = $this->admin();
        $this->kernel->handle($this->authRequest('POST', '/admin/posts/new', $admin, [
            'title' => '并发基线', 'slug' => 'rv-ok', 'bodyMd' => 'v1', 'status' => 'draft',
        ]));
        $html = (string) $this->kernel->handle($this->authRequest('GET', '/admin/posts/rv-ok/edit', $admin))->getBody();
        preg_match('/name="resourceVersion" value="(\d+)"/', $html, $m);
        self::assertNotEmpty($m[1] ?? '');

        $res = $this->kernel->handle($this->authRequest('POST', '/admin/posts/rv-ok/edit', $admin, [
            'title' => '并发基线改', 'slug' => 'rv-ok', 'bodyMd' => 'v2', 'status' => 'draft',
            'resourceVersion' => $m[1],
        ]));
        self::assertSame(302, $res->getStatusCode());
        $post = $this->kernel->extensions()->find(Post::KIND, 'rv-ok');
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('v2', $post->bodyMd());
    }

    public function testStaleResourceVersionShowsConflictAndKeepsInput(): void
    {
        $admin = $this->admin();
        $this->kernel->handle($this->authRequest('POST', '/admin/posts/new', $admin, [
            'title' => '冲突站', 'slug' => 'rv-clash', 'bodyMd' => '服务器版本', 'status' => 'draft',
        ]));
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/posts/rv-clash/edit', $admin, [
            'title' => '冲突站', 'slug' => 'rv-clash', 'bodyMd' => '我的未保存输入', 'status' => 'draft',
            'resourceVersion' => '999999',
        ]));
        self::assertSame(200, $res->getStatusCode()); // 冲突页按 200 正常渲染表单,绝不静默丢弃(11 §3.4)
        $html = (string) $res->getBody();
        self::assertStringContainsString('保存冲突', $html);
        self::assertStringContainsString('存在更新版本,本地副本已保存,请复制后重试', $html);
        self::assertStringContainsString('alert-danger', $html);
        self::assertStringContainsString('我的未保存输入', $html); // 绝不静默丢弃(11 §3.4)
        $post = $this->kernel->extensions()->find(Post::KIND, 'rv-clash');
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('服务器版本', $post->bodyMd()); // 未被覆盖
    }

    /** 两个编辑会话先后携带同一 resourceVersion 保存:后一次得到冲突横幅且输入未丢。 */
    public function testTwoSessionsSameResourceVersionSecondConflicts(): void
    {
        $admin = $this->admin();
        $this->kernel->handle($this->authRequest('POST', '/admin/posts/new', $admin, [
            'title' => '双会话', 'slug' => 'rv-two', 'bodyMd' => 'v0', 'status' => 'published',
        ]));
        $html = (string) $this->kernel->handle($this->authRequest('GET', '/admin/posts/rv-two/edit', $admin))->getBody();
        preg_match('/name="resourceVersion" value="(\d+)"/', $html, $m);
        self::assertNotEmpty($m[1] ?? '');
        $sharedRv = $m[1];

        // 会话 A 先保存成功
        $resA = $this->kernel->handle($this->authRequest('POST', '/admin/posts/rv-two/edit', $admin, [
            'title' => '双会话', 'slug' => 'rv-two', 'bodyMd' => '会话A的版本', 'status' => 'published',
            'resourceVersion' => $sharedRv,
        ]));
        self::assertSame(302, $resA->getStatusCode());

        // 会话 B 仍持旧 resourceVersion → 冲突回显,不覆盖
        $resB = $this->kernel->handle($this->authRequest('POST', '/admin/posts/rv-two/edit', $admin, [
            'title' => '双会话', 'slug' => 'rv-two', 'bodyMd' => '会话B的输入', 'status' => 'published',
            'resourceVersion' => $sharedRv,
        ]));
        self::assertSame(200, $resB->getStatusCode());
        $htmlB = (string) $resB->getBody();
        self::assertStringContainsString('alert-danger', $htmlB);
        self::assertStringContainsString('冲突', $htmlB);
        self::assertStringContainsString('会话B的输入', $htmlB);
        $post = $this->kernel->extensions()->find(Post::KIND, 'rv-two');
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('会话A的版本', $post->bodyMd());
    }

    public function testPreviewRendersGfmAndSanitizes(): void
    {
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/preview', $this->admin(), [
            'bodyMd' => "| A | B |\n| - | - |\n| 1 | 2 |\n\n<script>evil()</script>\n\n[x](javascript:alert)",
        ]));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        $html = (string) $res->getBody();
        self::assertStringContainsString('<table>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
    }

    public function testPreviewRequiresAuth(): void
    {
        $res = $this->kernel->handle($this->request('POST', '/admin/preview', ['bodyMd' => 'x']));
        self::assertSame(401, $res->getStatusCode());
    }

    public function testPreviewRendersInlineMarkdown(): void
    {
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/preview', $this->admin(), [
            'bodyMd' => "**粗体** 与 ~~删除~~ 及 `code`",
        ]));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        self::assertStringContainsString('<strong>粗体</strong>', $html);
        self::assertStringContainsString('<del>删除</del>', $html);
        self::assertStringContainsString('<code>code</code>', $html);
    }

    /** 非法 CSRF:双提交不一致按 CsrfMiddleware 行为拒绝(403)。 */
    public function testPreviewRejectsInvalidCsrf(): void
    {
        $cookies = $this->admin();
        $res = $this->kernel->handle($this->request('POST', '/admin/preview',
            ['bodyMd' => '**粗**'],
            ['X-CSRF-Token' => 'wrong-token'],
            $cookies,
        ));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testDashboardPublishActionDoesNotDelete(): void
    {
        $admin = $this->admin();
        $this->kernel->handle($this->authRequest('POST', '/admin/posts/new', $admin, [
            'title' => '发布回归', 'slug' => 'pub-regress', 'bodyMd' => 'x', 'status' => 'draft',
        ]));
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/posts/pub-regress/publish', $admin));
        self::assertSame(302, $res->getStatusCode());
        $post = $this->kernel->extensions()->find(Post::KIND, 'pub-regress');
        self::assertInstanceOf(Post::class, $post, 'publish must not delete the post');
        self::assertSame('published', $post->status());
    }

    public function testSubscriberCannotAccessEditor(): void
    {
        $this->kernel->container->get(UserRepository::class)
            ->create('reader', 'reader@example.com', 'password123', 3);
        $cookies = $this->login('reader', 'password123');

        $res = $this->kernel->handle($this->authRequest('GET', '/admin/posts/new', $cookies));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/admin/dashboard?error=forbidden', $res->getHeaderLine('Location'));

        $res = $this->kernel->handle($this->authRequest('POST', '/admin/preview', $cookies, ['bodyMd' => 'x']));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testAuthorCannotEditOthersPost(): void
    {
        $admin = $this->admin();
        $this->kernel->handle($this->authRequest('POST', '/admin/posts/new', $admin, [
            'title' => '管理员文章', 'slug' => 'admin-post', 'bodyMd' => 'x', 'status' => 'published',
        ]));
        $this->kernel->container->get(UserRepository::class)
            ->create('writer', 'writer@example.com', 'password123', 2);
        $cookies = $this->login('writer', 'password123');

        $res = $this->kernel->handle($this->authRequest('GET', '/admin/posts/admin-post/edit', $cookies));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/admin/dashboard?error=forbidden', $res->getHeaderLine('Location'));
    }
}
