<?php

declare(strict_types=1);

namespace PenBlog\Auth\Contracts;

/**
 * 登录限流扩展点(09 §6):M2 不内置实现,容器注册即生效。
 */
interface LoginThrottleHandler
{
    /** 返回 false 表示被限流(429)。 */
    public function check(string $ip, string $username): bool;

    public function recordFailure(string $ip, string $username): void;

    public function clear(string $ip, string $username): void;
}
