<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

/**
 * 后台主题页 + 前台渲染链路(03 §7):激活 ink 后首页令牌生效,切回 paper 复原。
 */
final class ThemeAdminPageTest extends KernelTestCase
{
    public function testAdminThemesPageRequiresLogin(): void
    {
        $res = $this->kernel->handle($this->request('GET', '/admin/themes'));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/admin/login', $res->getHeaderLine('Location'));
    }

    public function testActivateSwitchesFrontendAndBack(): void
    {
        $cookies = $this->login('admin', 'password123');

        $res = $this->kernel->handle($this->authRequest('GET', '/admin/themes', $cookies));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        self::assertStringContainsString('Ink 墨', $html);
        self::assertStringContainsString('/admin/themes/ink/activate', $html);
        self::assertStringContainsString('当前主题', $html);

        // 激活 ink
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/themes/ink/activate', $cookies));
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('ok=', $res->getHeaderLine('Location'));
        self::assertSame('ink', $this->kernel->options()->get('active_theme'));

        // 前台首页:ink 令牌 + ink 自有 home 模板 + 页脚文字 Setting
        $this->kernel->theme()->saveSettings('ink', ['footer_text' => '墨 · 随笔']);
        $res = $this->kernel->handle($this->request('GET', '/'));
        self::assertSame(200, $res->getStatusCode());
        $home = (string) $res->getBody();
        self::assertStringContainsString('--color-accent:#0F766E;', $home);
        self::assertStringContainsString('墨 · 随笔', $home);
        self::assertStringNotContainsString('#4F46E5', $home, 'paper 令牌已被 ink 覆盖');

        // 非法值表单 → 302 error 回跳
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/themes/ink/settings', $cookies, ['layout' => 'masonry']));
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('error=theme_setting_invalid', $res->getHeaderLine('Location'));

        // 激活中的 ink 不可卸载;切回 paper 后首页复原
        $res = $this->kernel->handle($this->authRequest('POST', '/admin/themes/ink/uninstall', $cookies));
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('error=theme_active', $res->getHeaderLine('Location'));

        $res = $this->kernel->handle($this->authRequest('POST', '/admin/themes/paper/activate', $cookies));
        self::assertSame(302, $res->getStatusCode());
        $home = (string) $this->kernel->handle($this->request('GET', '/'))->getBody();
        self::assertStringNotContainsString('#0F766E', $home);
        self::assertStringContainsString('Powered by PenBlog', $home);
    }
}
