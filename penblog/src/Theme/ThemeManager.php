<?php

declare(strict_types=1);

namespace PenBlog\Theme;

use PenBlog\Api\ApiException;
use PenBlog\Kernel\Kernel;

/**
 * 主题系统(03):发现、继承链、模板层级、令牌编译、Setting 存储。
 * - 模板解析:子主题 → 父主题链 → 内置基底 paper → 内核 fallback(损坏不白屏)。
 * - 状态:激活主题存 Options `active_theme`;设置存 `theme.{name}.{setting}` 命名空间。
 */
final class ThemeManager
{
    public const BASE_THEME = 'paper';

    /** @var array<string, ThemeManifest> */
    private array $discovered = [];

    /** @var array<string, string> 目录名 => 发现错误 */
    private array $discoveryErrors = [];

    /** @var array<string, string> "name/tpl" => 模板路径缓存 */
    private array $templateCache = [];

    /** @var array<string, string> 主题名 => tokensCss 缓存 */
    private array $tokensCssCache = [];

    /** @var array<string, bool> 已告警的保底命中(去重) */
    private array $warned = [];

    private bool $hasDiscovered = false;

    public function __construct(
        private readonly Kernel $kernel,
        private readonly string $themesDir,
    ) {
    }

    public function themesDir(): string
    {
        return $this->themesDir;
    }

    // ---------------------------------------------------------------- 发现

    public function discover(): void
    {
        $this->discovered = [];
        $this->discoveryErrors = [];
        $this->hasDiscovered = true;
        foreach (glob($this->themesDir . '/*/theme.json') ?: [] as $file) {
            $dir = basename(dirname($file));
            try {
                $manifest = ThemeManifest::fromFile($file);
            } catch (ThemeManifestException $e) {
                $this->discoveryErrors[$dir] = $e->getMessage();
                continue;
            }
            if ($manifest->name !== $dir) {
                $this->discoveryErrors[$dir] = "manifest name '{$manifest->name}' must match directory '{$dir}'";
                continue;
            }
            $this->discovered[$manifest->name] = $manifest;
        }
    }

    public function manifest(string $name): ?ThemeManifest
    {
        if (!$this->hasDiscovered) {
            $this->discover();
        }
        return $this->discovered[$name] ?? null;
    }

    /** @return array<string, string> */
    public function discoveryErrors(): array
    {
        if (!$this->hasDiscovered) {
            $this->discover();
        }
        return $this->discoveryErrors;
    }

    /**
     * 继承链:自身 → 父 → 祖父 …(03 §2)。缺父/成环即冲突。
     * @return list<ThemeManifest>
     */
    public function chain(string $name): array
    {
        $chain = [];
        $seen = [];
        $current = $name;
        $first = true;
        while ($current !== null) {
            if (isset($seen[$current])) {
                throw ApiException::conflict(
                    'Circular theme extends: ' . implode(' -> ', array_keys($seen)) . " -> {$current}",
                    'theme_extends_cycle',
                );
            }
            $seen[$current] = true;
            $manifest = $this->manifest($current);
            if ($manifest === null) {
                if ($first) {
                    throw ApiException::notFound('Theme');
                }
                throw ApiException::conflict("theme parent '{$current}' is missing", 'theme_parent_missing');
            }
            $chain[] = $manifest;
            $current = $manifest->extends;
            $first = false;
        }
        return $chain;
    }

    // ---------------------------------------------------------------- 生命周期

    public function active(): string
    {
        $raw = (string) $this->kernel->options()->get('active_theme', self::BASE_THEME);
        $active = preg_replace('/[^a-z0-9_-]/', '', $raw) ?? '';
        if ($active === '' || $this->manifest($active) === null) {
            return self::BASE_THEME;
        }
        return $active;
    }

    public function activate(string $name): void
    {
        if ($this->manifest($name) === null) {
            throw ApiException::notFound('Theme');
        }
        $this->chain($name); // 父链必须完整
        $this->kernel->options()->set('active_theme', $name);
        $this->templateCache = [];
        $this->tokensCssCache = [];
        $this->kernel->events->doAction('theme.activated', $name);
    }

    public function deactivate(string $name): void
    {
        if ($this->manifest($name) === null) {
            throw ApiException::notFound('Theme');
        }
        if ($this->active() === $name) {
            throw ApiException::conflict("theme {$name} is active; activate another theme first", 'theme_active');
        }
        $this->kernel->events->doAction('theme.deactivated', $name);
    }

    /** 卸载:清 theme.{name}.* Options 命名空间;文件保留(03 §4)。 */
    public function uninstall(string $name): void
    {
        if ($this->manifest($name) === null) {
            throw ApiException::notFound('Theme');
        }
        if ($this->active() === $name) {
            throw ApiException::conflict("theme {$name} is active; activate another theme first", 'theme_active');
        }
        foreach (array_keys($this->kernel->options()->allWithPrefix('theme.' . $name . '.')) as $key) {
            $this->kernel->options()->delete($key);
        }
        unset($this->tokensCssCache[$name]);
        $this->kernel->events->doAction('theme.uninstalled', $name);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        if (!$this->hasDiscovered) {
            $this->discover();
        }
        $active = $this->active();
        $rows = [];
        foreach ($this->discovered as $name => $manifest) {
            $rows[] = $manifest->toArray() + [
                'state' => $name === $active ? 'active' : 'inactive',
                'hasSettings' => $manifest->settings !== [],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $rows;
    }

    // ---------------------------------------------------------------- 模板层级(03 §2)

    /** 解析模板路径:链上 templates → 基底 paper → 内核 fallback。 */
    public function templatePath(string $name, string $tpl): string
    {
        if (!preg_match('/^[a-z0-9_-]{1,80}$/', $tpl)) {
            throw ApiException::notFound('Template');
        }
        $key = $name . '/' . $tpl;
        if (isset($this->templateCache[$key])) {
            return $this->templateCache[$key];
        }
        try {
            $chain = $this->chain($name);
        } catch (ApiException) {
            $chain = []; // 主题损坏:直接走保底,前台不白屏
        }
        foreach ($chain as $manifest) {
            $path = $this->themesDir . '/' . $manifest->name . '/templates/' . $tpl . '.php';
            if (is_file($path)) {
                return $this->templateCache[$key] = $path;
            }
        }
        $base = $this->themesDir . '/' . self::BASE_THEME . '/templates/' . $tpl . '.php';
        if ($name !== self::BASE_THEME && is_file($base)) {
            $this->warnOnce($key, "template '{$tpl}' falls back to base theme '" . self::BASE_THEME . "' for theme '{$name}'");
            return $this->templateCache[$key] = $base;
        }
        $fallback = __DIR__ . '/fallback/' . $tpl . '.php';
        if (is_file($fallback)) {
            $this->warnOnce($key, "template '{$tpl}' falls back to kernel fallback for theme '{$name}'");
            return $this->templateCache[$key] = $fallback;
        }
        throw ApiException::notFound('Template');
    }

    /** 主题静态资源:链上 assets/{file} 第一个命中。 */
    public function assetPath(string $name, string $file): ?string
    {
        if (!preg_match('/^[a-z0-9_-]{1,80}\.[a-z0-9]{1,8}$/', $file)) {
            return null;
        }
        try {
            $chain = $this->chain($name);
        } catch (ApiException) {
            $chain = [];
        }
        foreach ($chain as $manifest) {
            $path = $this->themesDir . '/' . $manifest->name . '/assets/' . $file;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- 设计令牌(03 §3)

    /** 编译继承链合并后的令牌为 <style> 块(darkMode 按策略输出)。 */
    public function tokensCss(string $name): string
    {
        if (isset($this->tokensCssCache[$name])) {
            return $this->tokensCssCache[$name];
        }
        try {
            $chain = $this->chain($name);
        } catch (ApiException $e) {
            $this->warnOnce('tokens/' . $name, 'tokens skipped: ' . $e->getMessage());
            return $this->tokensCssCache[$name] = '';
        }
        $tokens = [];
        $dark = [];
        foreach (array_reverse($chain) as $manifest) { // 父先子后,子覆盖
            $tokens = array_merge($tokens, $manifest->tokens);
            $dark = array_merge($dark, $manifest->darkMode['tokens']);
        }
        if ($tokens === [] && $dark === []) {
            return $this->tokensCssCache[$name] = '';
        }
        $strategy = $chain[0]->darkMode['strategy'];
        $css = ':root{' . self::declarations($tokens) . '}';
        if ($dark !== []) {
            $css .= $strategy === 'media'
                ? '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . self::declarations($dark) . '}}'
                : ':root[data-theme="dark"]{' . self::declarations($dark) . '}';
        }
        return $this->tokensCssCache[$name] = '<style>' . $css . '</style>';
    }

    /** @param array<string, string> $tokens */
    private static function declarations(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $key => $value) {
            $out .= '--' . str_replace('.', '-', $key) . ':' . $value . ';';
        }
        return $out;
    }

    // ---------------------------------------------------------------- Setting(03 §5)

    /** @return list<array<string, mixed>> 声明的 settings 列表 */
    public function settingsSchema(string $name): array
    {
        $manifest = $this->manifest($name);
        if ($manifest === null) {
            throw ApiException::notFound('Theme');
        }
        return $manifest->settings;
    }

    /** 读取单个设置:Options 值按声明类型转换,缺省回 manifest default → 参数 default。 */
    public function option(string $name, string $setting, mixed $default = null): mixed
    {
        $decl = null;
        foreach ($this->manifest($name)?->settings ?? [] as $s) {
            if ($s['name'] === $setting) {
                $decl = $s;
                break;
            }
        }
        $raw = $this->kernel->options()->get("theme.{$name}.{$setting}");
        if ($raw === null) {
            return $decl['default'] ?? $default;
        }
        return match ($decl['type'] ?? 'string') {
            'boolean' => (bool) $raw,
            'number' => 0 + $raw,
            default => (string) $raw,
        };
    }

    /** 当前激活主题的便捷读取。 */
    public function activeOption(string $setting, mixed $default = null): mixed
    {
        return $this->option($this->active(), $setting, $default);
    }

    /** @return array<string, mixed> 全部设置当前值(缺省回 default) */
    public function settingValues(string $name): array
    {
        $out = [];
        foreach ($this->settingsSchema($name) as $decl) {
            $out[$decl['name']] = $this->option($name, $decl['name'], $decl['default'] ?? null);
        }
        return $out;
    }

    /**
     * 保存设置(03 §5):按声明类型转换/校验;未声明键忽略。
     * @param array<string, mixed> $values 表单输入
     */
    public function saveSettings(string $name, array $values): void
    {
        $manifest = $this->manifest($name);
        if ($manifest === null) {
            throw ApiException::notFound('Theme');
        }
        foreach ($manifest->settings as $decl) {
            $key = $decl['name'];
            $type = $decl['type'];
            if ($type === 'boolean') {
                $value = isset($values[$key]) && ($values[$key] === '1' || $values[$key] === true || $values[$key] === 1);
            } else {
                if (!array_key_exists($key, $values)) {
                    continue;
                }
                $raw = $values[$key];
                $value = match ($type) {
                    'number' => self::clampNumber($raw, $decl),
                    'radio' => self::checkRadio($name, $key, $raw, (array) $decl['options']),
                    default => mb_substr(trim((string) $raw), 0, 2000),
                };
            }
            $this->kernel->options()->set("theme.{$name}.{$key}", $value);
        }
    }

    /** @param array<string, mixed> $decl */
    private static function clampNumber(mixed $raw, array $decl): int|float
    {
        if (!is_numeric($raw)) {
            return 0 + ($decl['default'] ?? 0);
        }
        $n = 0 + $raw;
        if (isset($decl['min'])) {
            $n = max($n, $decl['min']);
        }
        if (isset($decl['max'])) {
            $n = min($n, $decl['max']);
        }
        return is_int($n) || (is_float($n) && floor($n) === $n) ? (int) $n : $n;
    }

    /** @param list<string> $options */
    private static function checkRadio(string $theme, string $key, mixed $raw, array $options): string
    {
        $value = (string) $raw;
        if (!in_array($value, $options, true)) {
            throw ApiException::conflict("invalid value for setting {$key}", 'theme_setting_invalid');
        }
        return $value;
    }

    private function warnOnce(string $id, string $message): void
    {
        if (isset($this->warned[$id])) {
            return;
        }
        $this->warned[$id] = true;
        $this->kernel->logger()->warning('theme: ' . $message);
    }
}
