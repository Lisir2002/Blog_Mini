<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

use PenBlog\Repository\SqlDriver;

/**
 * Secret 存储(02 §6):值只进不出 —— 无列表 API,永不写入日志/响应。
 * 配置了 app.secret_key 时用 libsodium 静态加密。
 */
final class SecretStore
{
    public function __construct(
        private readonly SqlDriver $driver,
        private readonly ?string $encryptionKey = null,
    ) {
    }

    public function put(string $name, string $value): void
    {
        $stored = ($this->encryptionKey !== null && function_exists('sodium_crypto_secretbox'))
            ? 'sodium:' . base64_encode(sodium_crypto_secretbox($value, str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key()))
            : 'plain:' . base64_encode($value);
        $this->driver->upsert(
            'secrets',
            ['name' => $name, 'value' => $stored, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')],
            ['name'],
            ['value', 'updated_at'],
        );
    }

    public function get(string $name): ?string
    {
        $stmt = $this->driver->pdo()->prepare('SELECT value FROM secrets WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $raw = (string) $row['value'];
        if (str_starts_with($raw, 'sodium:')) {
            if (!function_exists('sodium_crypto_secretbox')) {
                return null; // 无法解密,视为缺失
            }
            $sealed = base64_decode(substr($raw, 7), true);
            // nonce 全零(确定性),仅静态加密用途;解密失败返回 null
            $plain = sodium_crypto_secretbox_open($sealed, str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
            return $plain === false ? null : $plain;
        }
        return base64_decode(substr($raw, 6), true) ?: null;
    }

    public function has(string $name): bool
    {
        $stmt = $this->driver->pdo()->prepare('SELECT 1 FROM secrets WHERE name = :name');
        $stmt->execute(['name' => $name]);
        return $stmt->fetch() !== false;
    }

    public function delete(string $name): void
    {
        $stmt = $this->driver->pdo()->prepare('DELETE FROM secrets WHERE name = :name');
        $stmt->execute(['name' => $name]);
    }

    private function key(): string
    {
        return sodium_crypto_generichash((string) $this->encryptionKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
