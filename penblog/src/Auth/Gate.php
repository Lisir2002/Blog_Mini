<?php

declare(strict_types=1);

namespace PenBlog\Auth;

/**
 * RBAC 判定(06 §2 / 09 §1):权限串 {kind}:{action} + system:manage;
 * PAT 权限 = 等级权限 ∩ scopes;L0 通配 `*`。
 */
final class Gate
{
    /**
     * @param list<string> $permissions 已解析(等级 ∩ scopes)
     * @param list<string>|null $patScopes 经 PAT 认证时非 null
     */
    public function __construct(
        public readonly ?User $user,
        private readonly array $permissions = [],
        public readonly ?array $patScopes = null,
        public readonly string $title = '',
        public readonly string $color = '#57534E',
    ) {
    }

    public static function anonymous(): self
    {
        return new self(null);
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function level(): int
    {
        return $this->user?->level() ?? 3;
    }

    public function allows(string $permission): bool
    {
        if ($this->user === null) {
            return false;
        }
        return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true);
    }

    /** 认证即可(任何等级),用于 user/me 这类"本人"端点。 */
    public function requireAuth(): self
    {
        if ($this->user === null) {
            throw \PenBlog\Api\ApiException::unauthorized();
        }
        return $this;
    }

    public function require(string $permission): self
    {
        if (!$this->allows($permission)) {
            throw \PenBlog\Api\ApiException::forbidden("需要权限 {$permission}");
        }
        return $this;
    }

    /** 文章写权:L0/L1 管所有,L2 仅自己(09 §1)。 */
    public function canWritePost(string $authorUsername): bool
    {
        if ($this->allows('post:manage')) {
            return true;
        }
        return $this->allows('post:write')
            && $this->user !== null
            && $this->user->username() === $authorUsername;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }
}
