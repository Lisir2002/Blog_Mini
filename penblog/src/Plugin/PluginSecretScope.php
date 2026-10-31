<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

use PenBlog\Kernel\SecretStore;

/**
 * Secret 隔离(04 §3/§5):仅 manifest 声明过的 secretRefs 可存取;
 * 存储键 plugin.{name}.secret.{ref},值只进不出(SecretStore 无列表)。
 */
final class PluginSecretScope
{
    /** @param list<string> $declaredRefs */
    public function __construct(
        private readonly string $plugin,
        private readonly array $declaredRefs,
        private readonly SecretStore $store,
        private readonly PluginContext $ctx,
    ) {
    }

    public function get(string $ref): ?string
    {
        $this->ctx->guard('secret.read');
        $this->assertDeclared($ref);
        return $this->store->get($this->key($ref));
    }

    public function put(string $ref, string $value): void
    {
        $this->ctx->guard('secret.write');
        $this->assertDeclared($ref);
        $this->store->put($this->key($ref), $value);
    }

    public function has(string $ref): bool
    {
        $this->ctx->guard('secret.read');
        $this->assertDeclared($ref);
        return $this->store->has($this->key($ref));
    }

    public function delete(string $ref): void
    {
        $this->ctx->guard('secret.write');
        $this->assertDeclared($ref);
        $this->store->delete($this->key($ref));
    }

    private function assertDeclared(string $ref): void
    {
        if (!in_array($ref, $this->declaredRefs, true)) {
            throw PermissionDenied::for($this->plugin, "secret.{$ref} (not in manifest secretRefs)");
        }
    }

    private function key(string $ref): string
    {
        return 'plugin.' . $this->plugin . '.secret.' . $ref;
    }
}
