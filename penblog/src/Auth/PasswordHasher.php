<?php

declare(strict_types=1);

namespace PenBlog\Auth;

/**
 * 09 §3:Argon2id 优先,bcrypt 兜底(沙箱无 sodium 时自动降级)。
 */
final class PasswordHasher
{
    public function hash(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($plain, $algo);
    }

    public function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo);
    }
}
