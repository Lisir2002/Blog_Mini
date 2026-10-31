<?php

declare(strict_types=1);

namespace PenBlog\Repository;

use PDO;

final class MysqlDriver extends AbstractDriver
{
    private PDO $pdo;

    public function __construct(string $host, int $port, string $database, string $user, string $password)
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** 建库用(安装向导):数据库不存在时先建库再实例化。 */
    public static function ensureDatabase(string $host, int $port, string $database, string $user, string $password): void
    {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $database),
        ));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function quoteIdentifier(string $ident): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
            throw new \InvalidArgumentException("Illegal identifier: {$ident}");
        }
        return '`' . $ident . '`';
    }

    public function upsert(string $table, array $data, array $keyCols, array $updateCols): void
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $cols)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)),
            implode(', ', array_map(
                fn (string $c): string => $this->quoteIdentifier($c) . ' = VALUES(' . $this->quoteIdentifier($c) . ')',
                $updateCols,
            )),
        );
        $this->pdo->prepare($sql)->execute($data);
    }

    protected function tableSuffix(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    protected function renderType(string $type, array $opts): string
    {
        return match ($type) {
            'pk' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'string' => 'VARCHAR(' . (int) ($opts['length'] ?? 255) . ')',
            'text' => 'TEXT',
            'json' => 'JSON',
            'timestamp' => 'DATETIME',
            'integer' => 'INT',
            'bigint' => 'BIGINT UNSIGNED',
            'boolean' => 'TINYINT(1)',
            default => throw new \InvalidArgumentException("Unknown type: {$type}"),
        };
    }

    protected function renderDefault(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            $value === 'CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
            default => "'" . str_replace("'", "''", (string) $value) . "'",
        };
    }
}
