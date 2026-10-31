<?php

declare(strict_types=1);

namespace PenBlog\Auth\Contracts;

use PenBlog\Auth\User;

/**
 * 二次验证扩展点(09 §6):密码校验通过后调用;
 * verify 返回 false 时登录路由须以 401 two_factor_required 拒绝。
 */
interface TwoFactorHandler
{
    public function isEnrolled(User $user): bool;

    public function verify(User $user, ?string $code): bool;
}
