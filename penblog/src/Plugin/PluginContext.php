<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\EventBus;
use PenBlog\Kernel\Kernel;
use PenBlog\Kernel\OptionsStore;
use PenBlog\Kernel\SecretStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * 插件唯一权力入口(04 §3):窄接口 + permissions 声明校验 + 命名空间隔离。
 * 插件不接收 Container/Router/DB 原始对象;所有注册经 Manager 记账,可撤销。
 */
final class PluginContext
{
    /** @var array<string, bool> */
    private readonly array $permissions;

    public function __construct(
        public readonly string $plugin,
        array $permissions,
        private readonly Kernel $kernel,
        private readonly PluginManager $manager,
    ) {
        $this->permissions = array_fill_keys($permissions, true);
    }

    public function events(): EventBus
    {
        $this->guard('event.listen');
        return $this->manager->scopedEvents($this->plugin, $this->kernel->events);
    }

    /**
     * RouterPoint / APIEndpoint 扩展点(04 §4)。
     * $rbac 非空时激活 Gate 权限校验;$summary 供 OpenAPI 动态文档。
     */
    public function registerRoute(string $method, string $path, callable $handler, string $rbac = '', string $summary = ''): void
    {
        $this->guard('router.register');
        if (!preg_match('#^/[a-z0-9/_{}.: -]*$#i', $path)) {
            throw new \InvalidArgumentException("Invalid route path: {$path}");
        }
        $wrapped = static function (ServerRequestInterface $req) use ($handler, $rbac): ResponseInterface {
            if ($rbac !== '') {
                AuthMiddleware::gate($req)->require($rbac);
            }
            return $handler($req);
        };
        $this->kernel->router->add($method, $path, $wrapped);
        $this->manager->trackRoute($this->plugin, strtoupper($method), $path, $summary, $rbac);
    }

    public function registerMenuItem(string $area, MenuItem $item): void
    {
        $this->guard('menu.register');
        if ($item->area !== $area) {
            throw new \InvalidArgumentException('MenuItem area mismatch');
        }
        $this->manager->trackMenu($this->plugin, $item);
    }

    public function registerSettingSection(SettingSection $section): void
    {
        $this->guard('settings.register');
        $this->manager->trackSection($this->plugin, $section);
    }

    /** ExtensionKind 扩展点(04 §4):注册新数据模型 kind,deactivate 时回收。 */
    public function registerExtension(string $kind, bool $public = false): void
    {
        $this->guard('extension.register');
        if (!preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $kind)) {
            throw new \InvalidArgumentException('Extension kind must be PascalCase');
        }
        $this->kernel->extensions()->registerType($kind, \PenBlog\Kernel\Extension::class);
        $this->manager->trackKind($this->plugin, $kind, $public);
    }

    public function options(): PluginOptionsScope
    {
        return new PluginOptionsScope($this->plugin, $this->kernel->container->get(OptionsStore::class), $this);
    }

    public function secrets(): PluginSecretScope
    {
        return new PluginSecretScope(
            $this->plugin,
            $this->manager->secretRefs($this->plugin),
            $this->kernel->container->get(SecretStore::class),
            $this,
        );
    }

    public function logger(): LoggerInterface
    {
        return $this->kernel->logger();
    }

    /** @throws PermissionDenied */
    public function guard(string $permission): void
    {
        if (!isset($this->permissions[$permission])) {
            throw PermissionDenied::for($this->plugin, $permission);
        }
    }
}
