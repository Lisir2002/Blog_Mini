<?php

declare(strict_types=1);

namespace PenBlog\Auth\Contracts;

/**
 * 会话生命周期扩展点(09 §4):主题/插件可接管"记住我"时长与清理策略。
 */
interface SessionManager
{
    public function ttlSeconds(bool $remember): int;

    public function purgeExpired(): int;
}
