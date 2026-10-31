<?php

declare(strict_types=1);

namespace PenBlog\Auth;

use PenBlog\Repository\SqlDriver;

/**
 * 服务端会话(09 §4):sessions 表存 token 的 SHA-256;登录成功即轮换 ID
 * (旧会话 revoke + 新 create,防会话固定);记住我 30 天,默认 2 小时。
 */
final class SessionStore
{
    public const COOKIE = 'penblog_session';
    public const CSRF_COOKIE = 'penblog_csrf';
    public const TTL_SECONDS = 7200;
    public const REMEMBER_TTL_SECONDS = 2592000; // 30 天

    public function __construct(private readonly SqlDriver $driver)
    {
    }

    /** 返回原始 token(只出现在 Cookie 里一次),库中只存哈希。 */
    public function create(int $userId, ?string $ip, ?string $ua, bool $remember = false): string
    {
        $raw = bin2hex(random_bytes(32));
        $ttl = $remember ? self::REMEMBER_TTL_SECONDS : self::TTL_SECONDS;
        $this->driver->insertReturningId('sessions', [
            'token_hash' => self::hash($raw),
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => $ua === null ? null : substr($ua, 0, 255),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $ttl),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return $raw;
    }

    /** @return array{user_id:int, token_hash:string}|null 会话有效则返回行(含过期校验) */
    public function validate(string $rawToken): ?array
    {
        if ($rawToken === '' || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
            return null;
        }
        $stmt = $this->driver->pdo()->prepare(
            'SELECT s.user_id, s.token_hash, s.expires_at, u.status AS user_status, u.deleted_at
             FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = :h'
        );
        $stmt->execute(['h' => self::hash($rawToken)]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        if ((string) $row['expires_at'] < gmdate('Y-m-d\TH:i:s\Z')
            || (string) $row['user_status'] !== 'active'
            || $row['deleted_at'] !== null) {
            $this->revokeByHash((string) $row['token_hash']);
            return null;
        }
        return ['user_id' => (int) $row['user_id'], 'token_hash' => (string) $row['token_hash']];
    }

    /** 登录轮换:撤销某用户全部旧会话。 */
    public function revokeAllForUser(int $userId): void
    {
        $stmt = $this->driver->pdo()->prepare('DELETE FROM sessions WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
    }

    public function revoke(string $rawToken): void
    {
        $this->revokeByHash(self::hash($rawToken));
    }

    public function revokeByHash(string $hash): void
    {
        $stmt = $this->driver->pdo()->prepare('DELETE FROM sessions WHERE token_hash = :h');
        $stmt->execute(['h' => $hash]);
    }

    public function purgeExpired(): int
    {
        $stmt = $this->driver->pdo()->prepare('DELETE FROM sessions WHERE expires_at < :t');
        $stmt->execute(['t' => gmdate('Y-m-d\TH:i:s\Z')]);
        return $stmt->rowCount();
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** 双提交 CSRF:非 HttpOnly Cookie,值即 token。 */
    public static function csrfToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
