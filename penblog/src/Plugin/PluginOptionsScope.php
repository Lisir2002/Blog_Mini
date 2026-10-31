<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

use PenBlog\Kernel\OptionsStore;

/**
 * Options 命名空间隔离(04 §3):插件只见 plugin.{name}.* 前缀,互相踩踏不可能。
 */
final class PluginOptionsScope
{
    public function __construct(
        private readonly string $plugin,
        private readonly OptionsStore $store,
        private readonly PluginContext $ctx,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ctx->guard('option.read');
        return $this->store->get($this->qualify($key), $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->ctx->guard('option.write');
        $this->store->set($this->qualify($key), $value);
    }

    public function delete(string $key): void
    {
        $this->ctx->guard('option.write');
        $this->store->delete($this->qualify($key));
    }

    /** @return array<string, mixed> 键已去前缀 */
    public function all(): array
    {
        $this->ctx->guard('option.read');
        $prefix = $this->prefix();
        $out = [];
        foreach ($this->store->allWithPrefix($prefix) as $name => $value) {
            $out[substr($name, strlen($prefix))] = $value;
        }
        return $out;
    }

    private function prefix(): string
    {
        return 'plugin.' . $this->plugin . '.';
    }

    private function qualify(string $key): string
    {
        if (!preg_match('/^[a-z0-9_.-]{1,64}$/', $key)) {
            throw new \InvalidArgumentException("Invalid option key: {$key}");
        }
        return $this->prefix() . $key;
    }
}
