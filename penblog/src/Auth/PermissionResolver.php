<?php

declare(strict_types=1);

namespace PenBlog\Auth;

use PenBlog\Kernel\ExtensionRegistry;

/**
 * 等级权限解析(09 §1):权威定义在 AccessLevel Extension;
 * 未种子化(如 M1 旧库)时回退内置 DEFAULTS。PAT 权限 = 等级权限 ∩ scopes(06 §2)。
 */
final class PermissionResolver
{
    public function __construct(private readonly ExtensionRegistry $registry)
    {
    }

    public function levelDefinition(int $level): AccessLevel
    {
        $found = $this->registry->find(AccessLevel::KIND, 'level-' . $level);
        if ($found instanceof AccessLevel) {
            return $found;
        }
        $def = AccessLevel::DEFAULTS[$level] ?? AccessLevel::DEFAULTS[3];
        return AccessLevel::fromSpec($def);
    }

    /** @param list<string>|null $patScopes */
    public function gate(?User $user, ?array $patScopes = null): Gate
    {
        if ($user === null) {
            return Gate::anonymous();
        }
        $definition = $this->levelDefinition($user->level());
        $permissions = $definition->permissions();
        if ($patScopes !== null && !in_array('*', $permissions, true)) {
            $permissions = array_values(array_intersect($permissions, $patScopes));
        }
        return new Gate($user, $permissions, $patScopes, $definition->title(), $definition->color());
    }
}
