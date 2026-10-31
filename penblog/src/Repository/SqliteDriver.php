<?php

declare(strict_types=1);

namespace PenBlog\Repository;

use PDO;

final class SqliteDriver extends AbstractDriver
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $inMemory = $path === ':memory:';
        if (!$inMemory) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        if (!$inMemory) {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function upsert(string $table, array $data, array $keyCols, array $updateCols): void
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $cols)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)),
            implode(', ', array_map($this->quoteIdentifier(...), $keyCols)),
            implode(', ', array_map(
                fn (string $c): string => $this->quoteIdentifier($c) . ' = excluded.' . $this->quoteIdentifier($c),
                $updateCols,
            )),
        );
        $this->pdo->prepare($sql)->execute($data);
    }

    protected function renderType(string $type, array $opts): string
    {
        return match ($type) {
            'pk' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'string', 'text', 'json', 'timestamp' => 'TEXT',
            'integer', 'bigint', 'boolean' => 'INTEGER',
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
