<?php

declare(strict_types=1);

namespace PenBlog\Auth;

use PenBlog\Kernel\Extension;

/**
 * 用户即 Extension(kind: User,09 §5)。spec 为可编辑档案字段;
 * email/password_hash 永不出现在非本人响应(publicDto)。
 */
final class User extends Extension
{
    public const KIND = 'User';

    public function username(): string
    {
        return $this->metadata->name;
    }

    public function email(): string
    {
        return (string) ($this->spec['email'] ?? '');
    }

    public function displayName(): string
    {
        return (string) ($this->spec['displayName'] ?? $this->username());
    }

    public function level(): int
    {
        return (int) ($this->spec['level'] ?? 3);
    }

    public function xp(): int
    {
        return (int) ($this->spec['xp'] ?? 0);
    }

    public function status(): string
    {
        return (string) ($this->spec['status'] ?? 'active');
    }

    public function avatar(): ?string
    {
        return isset($this->spec['avatar']) ? (string) $this->spec['avatar'] : null;
    }

    public function bio(): ?string
    {
        return isset($this->spec['bio']) ? (string) $this->spec['bio'] : null;
    }

    public function homepage(): ?string
    {
        return isset($this->spec['homepage']) ? (string) $this->spec['homepage'] : null;
    }

    public function id(): int
    {
        return (int) $this->metadata->uid;
    }

    /** 09 §5 公开只读 DTO:主题/API 匿名可见。 */
    public function publicDto(): array
    {
        return [
            'displayName' => $this->displayName(),
            'avatar' => $this->avatar(),
            'bio' => $this->bio(),
            'homepage' => $this->homepage(),
            'level' => $this->level(),
        ];
    }

    /** 本人视图:含 email,不含密码。 */
    public function selfDto(): array
    {
        return $this->spec + ['username' => $this->username()];
    }
}
