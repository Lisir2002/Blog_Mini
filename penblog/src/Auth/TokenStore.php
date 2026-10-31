<?php

declare(strict_types=1);

namespace PenBlog\Auth;

use PenBlog\Repository\SqlDriver;

/**
 * PAT(06 §2):`pb_` + 40 hex;库中只存哈希,前 8 位明文做查找索引;可 scopes 限定。
 */
final class TokenStore
{
    public function __construct(
        private readonly SqlDriver $driver,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /** @param list<string>|null $scopes null=继承等级全部权限 @return array{0:string,1:int} [原始令牌, 行id] */
    public function issue(int $userId, string $name, ?array $scopes, ?string $expiresAt = null): array
    {
        $raw = 'pb_' . bin2hex(random_bytes(20));
        $id = $this->driver->insertReturningId('personal_access_tokens', [
            'name' => $name,
            'token_prefix' => substr($raw, 3, 8),
            'token_hash' => $this->hasher->hash($raw),
            'user_id' => $userId,
            'scopes' => json_encode($scopes ?? [], JSON_UNESCAPED_UNICODE),
            'expires_at' => $expiresAt,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return [$raw, $id];
    }

    /** @return array{user_id:int, scopes:list<string>}|null */
    public function verify(string $raw): ?array
    {
        if (!preg_match('/^pb_[0-9a-f]{40}$/', $raw) || strlen($raw) < 11) {
            return null;
        }
        $stmt = $this->driver->pdo()->prepare(
            'SELECT t.*, u.status AS user_status, u.deleted_at
             FROM personal_access_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_prefix = :p'
        );
        $stmt->execute(['p' => substr($raw, 3, 8)]);
        $row = $stmt->fetch();
        if ($row === false || !$this->hasher->verify($raw, (string) $row['token_hash'])) {
            return null;
        }
        if ($row['expires_at'] !== null && (string) $row['expires_at'] < gmdate('Y-m-d\TH:i:s\Z')) {
            return null;
        }
        if ((string) $row['user_status'] !== 'active' || $row['deleted_at'] !== null) {
            return null;
        }
        $scopes = json_decode((string) $row['scopes'], true);
        return ['user_id' => (int) $row['user_id'], 'scopes' => is_array($scopes) && $scopes !== [] ? array_values($scopes) : null];
    }

    /** @return list<array<string, mixed>> 列表(不含哈希) */
    public function listForUser(int $userId): array
    {
        $stmt = $this->driver->pdo()->prepare(
            'SELECT id, name, token_prefix, scopes, expires_at, created_at
             FROM personal_access_tokens WHERE user_id = :u ORDER BY id'
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }

    public function revoke(int $id, int $userId): bool
    {
        $stmt = $this->driver->pdo()->prepare('DELETE FROM personal_access_tokens WHERE id = :id AND user_id = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
