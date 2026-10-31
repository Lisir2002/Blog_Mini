<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Api\ApiException;
use PenBlog\Theme\ThemeManager;

/**
 * ThemeManager:发现、继承链、模板层级、令牌编译、Setting 存储(03)。
 * 使用发行内置 themes/paper + themes/ink 真实目录。
 */
final class ThemeManagerTest extends KernelTestCase
{
    private ThemeManager $themes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->themes = $this->kernel->theme();
    }

    public function testDiscoversBundledThemes(): void
    {
        $names = array_column($this->themes->list(), 'name');
        self::assertSame(['ink', 'paper'], $names);
        self::assertSame([], $this->themes->discoveryErrors());
        $paper = $this->themes->manifest('paper');
        self::assertNotNull($paper);
        self::assertNull($paper->extends, 'paper 是基底主题');
    }

    public function testChainAndActiveLifecycle(): void
    {
        self::assertSame('paper', $this->themes->active(), '默认基底主题');
        self::assertSame(['ink', 'paper'], array_map(static fn ($m) => $m->name, $this->themes->chain('ink')));

        $this->themes->activate('ink');
        self::assertSame('ink', $this->themes->active());
        self::assertSame('ink', $this->kernel->options()->get('active_theme'));
        self::assertSame('active', $this->themes->list()[0]['state'], 'list 按名排序:ink 在前');
    }

    public function testDeactivateAndUninstallGuards(): void
    {
        $this->themes->activate('ink');
        try {
            $this->themes->deactivate('ink');
            self::fail('active theme must not deactivate');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('theme_active', $e->errorCode);
        }
        try {
            $this->themes->uninstall('ink');
            self::fail('active theme must not uninstall');
        } catch (ApiException $e) {
            self::assertSame('theme_active', $e->errorCode);
        }

        $this->themes->activate('paper');
        $this->themes->deactivate('ink'); // 非激活可停用(仅事件)
        $this->kernel->options()->set('theme.ink.footer_text', 'bye');
        $this->themes->uninstall('ink');
        self::assertNull($this->kernel->options()->get('theme.ink.footer_text'), '卸载清命名空间');
        self::assertFileExists($this->themes->themesDir() . '/ink/theme.json', '文件保留');
    }

    public function testActivateMissingThemeThrows(): void
    {
        $this->expectException(ApiException::class);
        $this->themes->activate('nope');
    }

    public function testTemplateHierarchy(): void
    {
        // ink 自带 home → ink;layout/404 → 基底 paper
        self::assertStringContainsString('/ink/templates/home.php', $this->themes->templatePath('ink', 'home'));
        self::assertStringContainsString('/paper/templates/layout.php', $this->themes->templatePath('ink', 'layout'));
        self::assertStringContainsString('/paper/templates/404.php', $this->themes->templatePath('ink', '404'));
        self::assertStringContainsString('/paper/templates/home.php', $this->themes->templatePath('paper', 'home'));
    }

    public function testBrokenThemeFallsBackWithoutWhiteScreen(): void
    {
        // 未注册主题名:模板走 paper/fallback,令牌为空串,渲染不抛异常
        self::assertStringContainsString('templates/layout.php', $this->themes->templatePath('ghost', 'layout'));
        self::assertSame('', $this->themes->tokensCss('ghost'));
        // 非法模板名拒绝
        $this->expectException(ApiException::class);
        $this->themes->templatePath('paper', '../../etc/passwd');
    }

    public function testTokensCssMergesChainAndDarkMode(): void
    {
        $css = $this->themes->tokensCss('ink');
        self::assertStringContainsString('<style>', $css);
        self::assertStringContainsString('--color-accent:#0F766E;', $css);
        self::assertStringContainsString('--color-accent-hover:#115E59;', $css);
        self::assertStringContainsString(':root[data-theme="dark"]{--color-accent:#5EEEE0;}', $css);
        self::assertStringNotContainsString('@media', $css, 'ink 用 class 策略');
    }

    public function testAssetPathResolvesAlongChain(): void
    {
        self::assertStringEndsWith('/paper/assets/paper.css', (string) $this->themes->assetPath('ink', 'paper.css'));
        self::assertNull($this->themes->assetPath('ink', 'ink.css'), 'ink 无自有资源');
        self::assertNull($this->themes->assetPath('paper', 'missing.css'));
        self::assertNull($this->themes->assetPath('paper', 'paper.css/../../x'), '非法文件名');
    }

    public function testSettingsSaveReadAndValidate(): void
    {
        $this->themes->activate('ink');
        self::assertSame(10, $this->themes->activeOption('post_per_page'), '缺省回 default');
        self::assertTrue((bool) $this->themes->activeOption('show_excerpt'));
        self::assertSame('grid', $this->themes->activeOption('layout'));

        $this->themes->saveSettings('ink', [
            'post_per_page' => '999',          // 钳到 max
            'show_excerpt' => '1',             // checkbox 勾选
            'layout' => 'list',
            'footer_text' => "  墨 · 随笔  \n",
            'undeclared_key' => 'ignored',
        ]);
        self::assertSame(50, $this->themes->activeOption('post_per_page'));
        self::assertTrue($this->themes->activeOption('show_excerpt'));
        self::assertSame('list', $this->themes->activeOption('layout'));
        self::assertSame('墨 · 随笔', $this->themes->activeOption('footer_text'));
        self::assertNull($this->kernel->options()->get('theme.ink.undeclared_key'), '未声明键忽略');

        // number 非数值回 default;boolean 未勾选为 false
        $this->themes->saveSettings('ink', ['post_per_page' => 'abc']);
        self::assertSame(10, $this->themes->activeOption('post_per_page'));
        $this->themes->saveSettings('ink', ['layout' => 'grid']);
        self::assertFalse($this->themes->option('ink', 'show_excerpt'), '未提交 checkbox → false');

        try {
            $this->themes->saveSettings('ink', ['layout' => 'masonry']);
            self::fail('radio 越界值必须拒绝');
        } catch (ApiException $e) {
            self::assertSame('theme_setting_invalid', $e->errorCode);
        }
    }

    public function testActiveSanitizedWhenOptionCorrupted(): void
    {
        $this->kernel->options()->set('active_theme', '../evil');
        self::assertSame('paper', $this->themes->active());
    }
}
