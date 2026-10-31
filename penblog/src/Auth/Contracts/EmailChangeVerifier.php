<?php

declare(strict_types=1);

namespace PenBlog\Auth\Contracts;

use PenBlog\Auth\User;

/**
 * 改邮箱验证扩展点(09 §3):注册后 PUT /user/me 改 email 需走确认链路,
 * 未注册实现则直接生效(M2 默认)。
 */
interface EmailChangeVerifier
{
    public function request(User $user, string $newEmail): void;

    public function confirm(User $user, string $token): bool;
}
