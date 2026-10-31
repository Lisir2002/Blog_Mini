<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit\Theme;

use PenBlog\Theme\ThemeManifest;
use PenBlog\Theme\ThemeManifestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * theme.json 清单校验(03 §3/§5)。
 */
final class ThemeManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function ink(): array
    {
        return [
            'name' => 'ink',
            'displayName' => 'Ink 墨',
            'version' => '1.0.0',
            'author' => 'PenBlog',
            'description' => '示例子主题',
            'tags' => ['minimal'],
            'extends' => 'paper',
            'tokens' => ['color.accent' => '#0F766E', 'color.accent-hover' => '#115E59'],
            'darkMode' => ['strategy' => 'class', 'tokens' => ['color.accent' => '#5EEEE0']],
            'settings' => [
                ['name' => 'footer_text', 'type' => 'textarea', 'label' => '页脚文字', 'default' => ''],
                ['name' => 'post_per_page', 'type' => 'number', 'label' => '每页文章数', 'default' => 10, 'min' => 3, 'max' => 50],
                ['name' => 'show_excerpt', 'type' => 'boolean', 'label' => '列表显示摘要', 'default' => true],
                ['name' => 'layout', 'type' => 'radio', 'label' => '列表布局', 'options' => ['list', 'grid'], 'default' => 'grid'],
            ],
        ];
    }

    public function testValidManifestParsesAllSections(): void
    {
        $m = ThemeManifest::fromArray(self::ink(), 'ink/theme.json');
        self::assertSame('ink', $m->name);
        self::assertSame('1.0.0', $m->version);
        self::assertSame('paper', $m->extends);
        self::assertSame('#0F766E', $m->tokens['color.accent']);
        self::assertSame('class', $m->darkMode['strategy']);
        self::assertSame('#5EEEE0', $m->darkMode['tokens']['color.accent']);
        self::assertCount(4, $m->settings);
        self::assertSame(['name', 'version', 'displayName', 'author', 'description', 'tags', 'extends'], array_keys($m->toArray()));
    }

    public function testBundledInkThemeJsonIsValid(): void
    {
        $m = ThemeManifest::fromFile(dirname(__DIR__, 3) . '/themes/ink/theme.json');
        self::assertSame('ink', $m->name);
        self::assertSame('paper', $m->extends);
    }

    public function testDarkModeStrategyDefaultsToClass(): void
    {
        $json = self::ink();
        unset($json['darkMode']);
        $m = ThemeManifest::fromArray($json);
        self::assertSame('class', $m->darkMode['strategy']);
        self::assertSame([], $m->darkMode['tokens']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidProvider(): array
    {
        $badName = self::ink();
        $badName['name'] = 'Ink_Theme';
        $badVersion = self::ink();
        $badVersion['version'] = '1.0';
        $selfExtends = self::ink();
        $selfExtends['extends'] = 'ink';
        $badTokenKey = self::ink();
        $badTokenKey['tokens'] = ['color.Accent' => '#000', '9bad' => '#000'];
        $unsafeToken = self::ink();
        $unsafeToken['tokens'] = ['color.accent' => 'red;} </style><script>'];
        $badStrategy = self::ink();
        $badStrategy['darkMode']['strategy'] = 'auto';
        $badType = self::ink();
        $badType['settings'][0]['type'] = 'color';
        $radioNoOptions = self::ink();
        unset($radioNoOptions['settings'][3]['options']);
        $radioBadDefault = self::ink();
        $radioBadDefault['settings'][3]['default'] = 'masonry';
        $emptyLabel = self::ink();
        $emptyLabel['settings'][0]['label'] = '  ';
        $numberBadDefault = self::ink();
        $numberBadDefault['settings'][1]['default'] = 'ten';

        return [
            'name 非法' => [$badName, 'kebab-case'],
            'version 非 semver' => [$badVersion, 'semver'],
            'extends 自引用' => [$selfExtends, 'itself'],
            'tokens 键非法' => [$badTokenKey, 'key invalid'],
            'tokens 值不安全' => [$unsafeToken, 'value unsafe'],
            'darkMode 策略非法' => [$badStrategy, 'strategy'],
            'settings 类型非法' => [$badType, 'type must be one of'],
            'radio 缺 options' => [$radioNoOptions, 'options required'],
            'radio 默认值越界' => [$radioBadDefault, 'must be one of options'],
            'label 为空' => [$emptyLabel, 'must not be empty'],
            'number 默认值非数值' => [$numberBadDefault, 'must be numeric'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidManifestsAreRejected(array $json, string $expectedFragment): void
    {
        try {
            ThemeManifest::fromArray($json, 'x/theme.json');
            self::fail('expected ThemeManifestException');
        } catch (ThemeManifestException $e) {
            self::assertStringContainsString($expectedFragment, $e->getMessage());
            self::assertNotEmpty($e->errors);
            self::assertSame('x/theme.json', $e->source);
        }
    }

    public function testFromFileRejectsBadJson(): void
    {
        $path = sys_get_temp_dir() . '/pb-theme-bad-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, '{not json');
        try {
            $this->expectException(ThemeManifestException::class);
            ThemeManifest::fromFile($path);
        } finally {
            @unlink($path);
        }
    }
}
