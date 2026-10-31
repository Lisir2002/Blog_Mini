<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

use PenBlog\Api\ApiException;
use PenBlog\Kernel\EventBus;
use PenBlog\Kernel\Kernel;

/**
 * 插件生命周期与注册表(04 §2/§3):
 * - 状态存 Options `system.plugins.{name}`(installed/active/inactive/failed)。
 * - 所有注册经 track* 记账,deactivate 统一回收(可撤销硬约束)。
 * - requires.plugins 拓扑激活 + 环检测;onActivate 异常 → failed 隔离,不影响内核。
 */
final class PluginManager
{
    public const INSTALLED = 'installed';
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const FAILED = 'failed';

    /** @var array<string, Manifest> 目录名 => manifest */
    private array $discovered = [];

    /** @var array<string, string> 发现失败:目录名 => 错误 */
    private array $discoveryErrors = [];

    /** @var array<string, PluginInterface> */
    private array $instances = [];

    /** @var array<string, bool> 激活递归中的插件(环检测) */
    private array $activating = [];

    /** @var array<string, list<array{method:string, pattern:string}>> */
    private array $routeRegs = [];

    /** @var array<string, list<int>> */
    private array $eventRegs = [];

    /** @var array<string, list<string>> */
    private array $kindRegs = [];

    /** @var list<array{plugin:string, item:MenuItem}> */
    private array $menuRegs = [];

    /** @var list<array{plugin:string, section:SettingSection}> */
    private array $sectionRegs = [];

    /** @var array<string, array<string, array{summary:string, permission:?string, public:bool, plugin:string}>> */
    private array $apiMeta = [];

    /** @var array<string, ScopedEventBus> */
    private array $scopes = [];

    /** @var array<string, bool> 已注册自动加载的根命名空间(跨实例去重) */
    private static array $autoloaded = [];

    private bool $booted = false;

    public function __construct(
        private readonly Kernel $kernel,
        private readonly string $pluginsDir,
    ) {
    }

    public function pluginsDir(): string
    {
        return $this->pluginsDir;
    }

    // ---------------------------------------------------------------- 发现

    public function discover(): void
    {
        $this->discovered = [];
        $this->discoveryErrors = [];
        foreach (glob($this->pluginsDir . '/*/plugin.json') ?: [] as $file) {
            $dir = basename(dirname($file));
            try {
                $manifest = Manifest::fromFile($file);
            } catch (ManifestException $e) {
                $this->discoveryErrors[$dir] = $e->getMessage();
                continue;
            }
            if ($manifest->name !== $dir) {
                $this->discoveryErrors[$dir] = "manifest name '{$manifest->name}' must match directory '{$dir}'";
                continue;
            }
            $rootNs = explode('\\', $manifest->entry)[0];
            $this->registerAutoloader($rootNs, dirname($file) . '/src');
            if (!class_exists($manifest->entry) || !is_subclass_of($manifest->entry, PluginInterface::class)) {
                $this->discoveryErrors[$dir] = "entry class {$manifest->entry} not loadable or does not implement PluginInterface";
                continue;
            }
            $this->discovered[$manifest->name] = $manifest;
        }
    }

    private function registerAutoloader(string $rootNs, string $srcDir): void
    {
        if (isset(self::$autoloaded[$rootNs])) {
            return;
        }
        self::$autoloaded[$rootNs] = true;
        spl_autoload_register(static function (string $class) use ($rootNs, $srcDir): void {
            if (!str_starts_with($class, $rootNs . '\\')) {
                return;
            }
            $path = $srcDir . '/' . str_replace('\\', '/', substr($class, strlen($rootNs) + 1)) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }

    // ---------------------------------------------------------------- 状态

    /** @return ?array{state:string, version:string, error:?string, installedAt:string, activatedAt:?string} */
    public function state(string $name): ?array
    {
        $raw = $this->kernel->options()->get('system.plugins.' . $name);
        return is_array($raw) && isset($raw['state']) ? $raw : null;
    }

    /** @param array<string, mixed> $patch */
    private function setStatus(string $name, array $patch): void
    {
        $current = $this->state($name) ?? [];
        $this->kernel->options()->set('system.plugins.' . $name, array_merge($current, $patch));
    }

    /** @return array<string, mixed> 插件目录 + 状态合并视图(API/后台列表用) */
    public function list(): array
    {
        if ($this->discovered === [] && $this->discoveryErrors === []) {
            $this->discover();
        }
        $rows = [];
        foreach ($this->discovered as $name => $manifest) {
            $rec = $this->state($name);
            $rows[] = $manifest->toArray() + [
                'state' => $rec['state'] ?? 'none',
                'error' => $rec['error'] ?? null,
                'installedAt' => $rec['installedAt'] ?? null,
                'activatedAt' => $rec['activatedAt'] ?? null,
            ];
        }
        // 文件已删除但状态残留的插件也可见(可卸载)
        foreach (array_keys($this->kernel->options()->allWithPrefix('system.plugins.')) as $key) {
            $name = substr($key, strlen('system.plugins.'));
            if (!isset($this->discovered[$name])) {
                $rec = $this->state($name) ?? [];
                $rows[] = ['name' => $name, 'version' => $rec['version'] ?? '', 'description' => '(文件缺失)',
                    'state' => $rec['state'] ?? 'none', 'error' => $rec['error'] ?? null,
                    'installedAt' => $rec['installedAt'] ?? null, 'activatedAt' => $rec['activatedAt'] ?? null,
                    'requires' => [], 'permissions' => [], 'secretRefs' => [], 'providesHooks' => []];
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $rows;
    }

    /** @return array<string, string> */
    public function discoveryErrors(): array
    {
        if ($this->discovered === [] && $this->discoveryErrors === []) {
            $this->discover();
        }
        return $this->discoveryErrors;
    }

    // ---------------------------------------------------------------- 生命周期

    public function install(string $name): void
    {
        $manifest = $this->requireManifest($name);
        if ($this->state($name) !== null) {
            throw ApiException::conflict("Plugin {$name} is already installed", 'plugin_installed');
        }
        if (isset($manifest->requires['php']) && !self::constraintOk($manifest->requires['php'], PHP_VERSION)) {
            throw ApiException::conflict("PHP version " . PHP_VERSION . " does not satisfy {$manifest->requires['php']}", 'plugin_requirement_unmet');
        }
        if (isset($manifest->requires['penblog']) && !self::constraintOk($manifest->requires['penblog'], Kernel::VERSION)) {
            throw ApiException::conflict("PenBlog " . Kernel::VERSION . " does not satisfy {$manifest->requires['penblog']}", 'plugin_requirement_unmet');
        }
        foreach ($manifest->requires['plugins'] ?? [] as $dep) {
            if ($this->state($dep) === null) {
                throw ApiException::conflict("dependency plugin {$dep} is not installed", 'plugin_dependency_missing');
            }
        }
        $this->setStatus($name, [
            'state' => self::INSTALLED, 'version' => $manifest->version, 'error' => null,
            'installedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'activatedAt' => null,
        ]);
    }

    /** 激活(含依赖拓扑);onActivate 异常 → failed + ApiException,内核不受影响。 */
    public function activate(string $name): void
    {
        $manifest = $this->requireManifest($name);
        $rec = $this->state($name);
        if ($rec === null) {
            throw ApiException::conflict("Plugin {$name} is not installed", 'plugin_not_installed');
        }
        // 幂等以内存实例为准:持久状态 active 但本请求未注册(如新 Kernel)时必须真正激活
        if (isset($this->instances[$name])) {
            return;
        }
        if (isset($this->activating[$name])) {
            throw ApiException::conflict(
                'Circular plugin dependency: ' . implode(' -> ', array_keys($this->activating)) . " -> {$name}",
                'plugin_dependency_cycle',
            );
        }
        $this->activating[$name] = true;
        try {
            foreach ($manifest->requires['plugins'] ?? [] as $dep) {
                $depState = $this->state($dep);
                if ($depState === null) {
                    throw ApiException::conflict("dependency plugin {$dep} is not installed", 'plugin_dependency_missing');
                }
                if ($depState['state'] !== self::ACTIVE) {
                    $this->activate($dep);
                }
            }
        } finally {
            unset($this->activating[$name]);
        }

        try {
            $instance = new $manifest->entry();
            $ctx = new PluginContext($name, $manifest->permissions, $this->kernel, $this);
            $instance->onActivate($ctx);
        } catch (\Throwable $e) {
            $this->revoke($name);
            unset($this->instances[$name], $this->scopes[$name]);
            $this->setStatus($name, ['state' => self::FAILED, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->kernel->logger()->warning("plugin '{$name}' activation failed: {$e->getMessage()}");
            throw ApiException::conflict("Plugin {$name} activation failed: " . $e->getMessage(), 'plugin_activation_failed');
        }

        $this->instances[$name] = $instance;
        $this->setStatus($name, ['state' => self::ACTIVE, 'error' => null, 'activatedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
        $this->kernel->events->doAction('plugin.activated', $name);
    }

    /** 停用:调 onDeactivate + 撤销全部注册(04 §2 硬约束)。 */
    public function deactivate(string $name): void
    {
        $rec = $this->state($name);
        if ($rec === null) {
            throw ApiException::notFound('Plugin');
        }
        if ($rec['state'] !== self::ACTIVE) {
            $this->revoke($name);
            $this->setStatus($name, ['state' => self::INACTIVE]);
            return;
        }
        foreach (array_keys($this->instances) as $other) {
            if ($other !== $name && in_array($name, $this->manifest($other)?->requires['plugins'] ?? [], true)) {
                throw ApiException::conflict("plugin {$name} is still required by active plugin {$other}", 'plugin_dependency_active');
            }
        }
        try {
            $this->instances[$name]?->onDeactivate();
        } catch (\Throwable $e) {
            $this->kernel->logger()->warning("plugin '{$name}' onDeactivate error: {$e->getMessage()}");
        }
        $this->revoke($name);
        unset($this->instances[$name], $this->scopes[$name]);
        $this->setStatus($name, ['state' => self::INACTIVE]);
        $this->kernel->events->doAction('plugin.deactivated', $name);
    }

    /** 卸载:清 plugin.{name}.* Options 与 Secret;文件保留(04 §2)。 */
    public function uninstall(string $name): void
    {
        $rec = $this->state($name);
        if ($rec === null) {
            throw ApiException::notFound('Plugin');
        }
        if ($rec['state'] === self::ACTIVE) {
            throw ApiException::conflict("deactivate plugin {$name} before uninstall", 'plugin_active');
        }
        $manifest = $this->discovered[$name] ?? null;
        if ($manifest !== null) {
            try {
                (new $manifest->entry())->onUninstall();
            } catch (\Throwable $e) {
                $this->kernel->logger()->warning("plugin '{$name}' onUninstall error: {$e->getMessage()}");
            }
        }
        foreach (array_keys($this->kernel->options()->allWithPrefix('plugin.' . $name . '.')) as $key) {
            $this->kernel->options()->delete($key);
        }
        foreach ($manifest?->secretRefs ?? [] as $ref) {
            $this->kernel->secrets()->delete('plugin.' . $name . '.secret.' . $ref);
        }
        $this->kernel->options()->delete('system.plugins.' . $name);
        unset($this->discovered[$name], $this->instances[$name], $this->scopes[$name]);
    }

    /** 请求引导:激活状态为 active 的插件(拓扑序),失败逐个隔离(02 §7)。 */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        try {
            $this->discover();
            foreach (array_keys($this->discovered) as $name) {
                if (($this->state($name) ?? ['state' => 'none'])['state'] === self::ACTIVE) {
                    try {
                        $this->activate($name);
                    } catch (\Throwable $e) {
                        // activate 内部已标记 failed 并记日志;此处仅隔离
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->kernel->logger()->warning('plugin boot failed: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- 注册记账(供 Context)

    public function scopedEvents(string $name, EventBus $inner): ScopedEventBus
    {
        return $this->scopes[$name] ??= new ScopedEventBus($inner, $this, $name);
    }

    public function trackEvent(string $name, int $handle): void
    {
        $this->eventRegs[$name][] = $handle;
    }

    public function trackRoute(string $name, string $method, string $pattern, string $summary, string $rbac): void
    {
        $this->routeRegs[$name][] = ['method' => $method, 'pattern' => $pattern];
        $this->apiMeta[$name]["{$method} {$pattern}"] = [
            'summary' => $summary !== '' ? $summary : $pattern,
            'permission' => $rbac !== '' ? $rbac : null,
            'public' => $rbac === '',
            'plugin' => $name,
        ];
    }

    public function trackMenu(string $name, MenuItem $item): void
    {
        $this->menuRegs[] = ['plugin' => $name, 'item' => $item];
    }

    public function trackSection(string $name, SettingSection $section): void
    {
        $this->sectionRegs[] = ['plugin' => $name, 'section' => $section];
    }

    public function trackKind(string $name, string $kind, bool $public): void
    {
        $this->kindRegs[$name][] = $kind;
    }

    /** @return list<string> */
    public function secretRefs(string $name): array
    {
        return $this->manifest($name)?->secretRefs ?? [];
    }

    /** deactivate 回收:路由/监听器/菜单/设置区块/kind/API 元数据全部撤销。 */
    private function revoke(string $name): void
    {
        foreach ($this->routeRegs[$name] ?? [] as $route) {
            $this->kernel->router->remove($route['method'], $route['pattern']);
        }
        unset($this->routeRegs[$name]);
        foreach ($this->eventRegs[$name] ?? [] as $handle) {
            $this->kernel->events->unlisten($handle);
        }
        unset($this->eventRegs[$name]);
        foreach ($this->kindRegs[$name] ?? [] as $kind) {
            $this->kernel->extensions()->unregisterType($kind);
        }
        unset($this->kindRegs[$name]);
        $this->menuRegs = array_values(array_filter($this->menuRegs, static fn (array $r): bool => $r['plugin'] !== $name));
        $this->sectionRegs = array_values(array_filter($this->sectionRegs, static fn (array $r): bool => $r['plugin'] !== $name));
        unset($this->apiMeta[$name]);
    }

    // ---------------------------------------------------------------- 查询(渲染层/OpenAPI)

    /** @return list<array{plugin:string, item:MenuItem}> */
    public function menuItems(string $area): array
    {
        $items = array_values(array_filter($this->menuRegs, static fn (array $r): bool => $r['item']->area === $area));
        usort($items, static fn (array $a, array $b): int => $a['item']->priority <=> $b['item']->priority);
        return $items;
    }

    /** @return list<array{plugin:string, section:SettingSection}> */
    public function settingSections(): array
    {
        return $this->sectionRegs;
    }

    /** @return array<string, array{summary:string, permission:?string, public:bool, plugin:string}> */
    public function apiMeta(): array
    {
        return array_merge(...array_values($this->apiMeta + [0 => []]));
    }

    /** SettingSection 表单保存(经命名空间 Options,值存 plugin.{name}.settings)。 */
    public function saveSettings(string $name, string $sectionId, array $values): void
    {
        $section = null;
        foreach ($this->sectionRegs as $reg) {
            if ($reg['plugin'] === $name && $reg['section']->id === $sectionId) {
                $section = $reg['section'];
            }
        }
        if ($section === null) {
            throw ApiException::notFound('SettingSection');
        }
        // 每字段独立 Option 键 plugin.{name}.settings.{section}.{field},
        // 插件经 ctx->options()->get('settings.{section}.{field}') 直接读取。
        foreach ($section->fields as $field) {
            $key = $field['name'];
            $raw = $values[$key] ?? null;
            $value = match ($field['type']) {
                'checkbox' => $raw === '1' || $raw === true || $raw === 1,
                'number' => $raw === null || $raw === '' ? null : 0 + $raw,
                default => mb_substr(trim((string) $raw), 0, 2000),
            };
            $this->kernel->options()->set("plugin.{$name}.settings.{$sectionId}.{$key}", $value);
        }
    }

    /** @return array<string, mixed> 当前值(缺省回退字段 default) */
    public function settingValues(string $name, SettingSection $section): array
    {
        $prefix = "plugin.{$name}.settings.{$section->id}.";
        $stored = $this->kernel->options()->allWithPrefix($prefix);
        $out = [];
        foreach ($section->fields as $field) {
            $full = $prefix . $field['name'];
            $out[$field['name']] = $stored[$full] ?? ($field['default'] ?? '');
        }
        return $out;
    }

    // ---------------------------------------------------------------- 内部

    public function manifest(string $name): ?Manifest
    {
        if ($this->discovered === [] && $this->discoveryErrors === []) {
            $this->discover();
        }
        return $this->discovered[$name] ?? null;
    }

    private function requireManifest(string $name): Manifest
    {
        $manifest = $this->manifest($name);
        if ($manifest === null) {
            throw ApiException::notFound('Plugin');
        }
        return $manifest;
    }

    private static function constraintOk(string $constraint, string $current): bool
    {
        if (preg_match('/^(>=|<=|==|!=|>|<)?\s*([\d.]+)$/', $constraint, $m) === 1) {
            return version_compare($current, $m[2], ($m[1] ?? '') !== '' ? $m[1] : '==');
        }
        return false;
    }
}
