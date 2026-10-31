<?php

declare(strict_types=1);

namespace PenBlog\Auth;

use PenBlog\Api\ApiException;
use PenBlog\Api\ValidationException;
use PenBlog\Kernel\Metadata;
use PenBlog\Repository\SqlDriver;

/**
 * users 表仓储:行 ↔ User Extension。role 列由 level 派生(向后兼容 M1)。
 */
final class UserRepository
{
    private const LEVEL_ROLE = [0 => 'admin', 1 => 'editor', 2 => 'author', 3 => 'subscriber'];

    public function __construct(
        private readonly SqlDriver $driver,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /** @param array<string, mixed> $fields */
    public function create(string $username, string $email, string $plainPassword, int $level = 3, array $fields = []): User
    {
        self::validateUsername($username);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::required('email');
        }
        if (strlen($plainPassword) < 8) {
            throw new ValidationException([['field' => 'password', 'reason' => 'min_length_8']], '密码至少 8 位');
        }
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $id = $this->driver->insertReturningId('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => $this->hasher->hash($plainPassword),
            'display_name' => (string) ($fields['displayName'] ?? $username),
            'role' => self::LEVEL_ROLE[$level] ?? 'subscriber',
            'level' => $level,
            'xp' => 0,
            'status' => (string) ($fields['status'] ?? 'active'),
            'avatar' => $fields['avatar'] ?? null,
            'bio' => $fields['bio'] ?? null,
            'homepage' => $fields['homepage'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->findById($id) ?? throw ApiException::conflict('用户创建失败');
    }

    public function findByUsername(string $username): ?User
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM users WHERE email = :e');
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    /** 登录入口:用户名或邮箱。 */
    public function findForLogin(string $identifier): ?User
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM users WHERE username = :i OR email = :i');
        $stmt->execute(['i' => $identifier]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM users WHERE id = :i');
        $stmt->execute(['i' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $changes 支持 displayName/avatar/bio/homepage/email/status/level/passwordPlain */
    public function update(User $user, array $changes): User
    {
        $set = [];
        $bind = ['id' => $user->id()];
        $map = ['displayName' => 'display_name', 'avatar' => 'avatar', 'bio' => 'bio', 'homepage' => 'homepage', 'status' => 'status', 'email' => 'email'];
        foreach ($map as $specKey => $col) {
            if (array_key_exists($specKey, $changes)) {
                $set[] = $this->driver->quoteIdentifier($col) . ' = :' . $col;
                $bind[$col] = $changes[$specKey];
            }
        }
        if (isset($changes['level'])) {
            $level = (int) $changes['level'];
            if (!isset(self::LEVEL_ROLE[$level])) {
                throw new ValidationException([['field' => 'level', 'reason' => 'invalid']], "非法等级 {$level}");
            }
            $set[] = 'level = :level';
            $bind['level'] = $level;
            $set[] = 'role = :role';
            $bind['role'] = self::LEVEL_ROLE[$level];
        }
        if (isset($changes['passwordPlain'])) {
            $set[] = 'password_hash = :ph';
            $bind['ph'] = $this->hasher->hash((string) $changes['passwordPlain']);
        }
        if ($set !== []) {
            $set[] = 'updated_at = :ts';
            $bind['ts'] = gmdate('Y-m-d\TH:i:s\Z');
            $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id';
            $this->driver->pdo()->prepare($sql)->execute($bind);
        }
        return $this->findById($user->id()) ?? $user;
    }

    public function setLevel(string $username, int $level): User
    {
        $user = $this->findByUsername($username) ?? throw ApiException::notFound('User');
        return $this->update($user, ['level' => $level]);
    }

    public function touchLastActive(int $userId): void
    {
        $stmt = $this->driver->pdo()->prepare('UPDATE users SET last_active_at = :t WHERE id = :i');
        $stmt->execute(['t' => gmdate('Y-m-d\TH:i:s\Z'), 'i' => $userId]);
    }

    public function passwordHashFor(int $userId): string
    {
        $stmt = $this->driver->pdo()->prepare('SELECT password_hash FROM users WHERE id = :i');
        $stmt->execute(['i' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? '' : (string) $row['password_hash'];
    }

    /** 登录时透明升级哈希算法(09 §3)。 */
    public function rehashIfNeeded(User $user, string $plain): void
    {
        $stmt = $this->driver->pdo()->prepare('SELECT password_hash FROM users WHERE id = :i');
        $stmt->execute(['i' => $user->id()]);
        $row = $stmt->fetch();
        if ($row !== false && $this->hasher->needsRehash((string) $row['password_hash'])) {
            $upd = $this->driver->pdo()->prepare('UPDATE users SET password_hash = :p WHERE id = :i');
            $upd->execute(['p' => $this->hasher->hash($plain), 'i' => $user->id()]);
        }
    }

    /** @return array<int, User> 批量,供主题/API 署名解析 */
    public function findManyByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->driver->pdo()->prepare("SELECT * FROM users WHERE id IN ({$in})");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $u = $this->hydrate($row);
            $out[$u->id()] = $u;
        }
        return $out;
    }

    public static function validateUsername(string $username): void
    {
        // 09 §3:3-64 字符 [a-zA-Z0-9_-]
        if (!preg_match('/^[a-zA-Z0-9_-]{3,64}$/', $username)) {
            throw new ValidationException([['field' => 'username', 'reason' => 'pattern']], '用户名需 3-64 位,仅限字母数字 _ -');
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        $spec = [
            'username' => (string) $row['username'],
            'email' => (string) $row['email'],
            'displayName' => (string) $row['display_name'],
            'level' => (int) ($row['level'] ?? 3),
            'xp' => (int) ($row['xp'] ?? 0),
            'status' => (string) ($row['status'] ?? 'active'),
        ];
        foreach (['avatar', 'bio', 'homepage'] as $k) {
            if (($row[$k] ?? null) !== null) {
                $spec[$k] = (string) $row[$k];
            }
        }
        return new User(new Metadata(
            name: $spec['username'],
            uid: (string) $row['id'],
            kind: User::KIND,
            creationTimestamp: (string) $row['created_at'],
            updateTimestamp: (string) $row['updated_at'],
        ), $spec);
    }
}
