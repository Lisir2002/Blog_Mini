<?php

declare(strict_types=1);

namespace PenBlog\Repository;

final class DriverFactory
{
    /** @param array<string, mixed> $dbConfig 形如 config['db'] */
    public static function make(array $dbConfig): SqlDriver
    {
        return match ($dbConfig['driver'] ?? 'sqlite') {
            'sqlite' => new SqliteDriver((string) ($dbConfig['path'] ?? 'storage/data.db')),
            'mysql' => new MysqlDriver(
                (string) ($dbConfig['host'] ?? '127.0.0.1'),
                (int) ($dbConfig['port'] ?? 3306),
                (string) ($dbConfig['database'] ?? 'penblog'),
                (string) ($dbConfig['username'] ?? 'root'),
                (string) ($dbConfig['password'] ?? ''),
            ),
            default => throw new \InvalidArgumentException('Unknown db driver: ' . $dbConfig['driver']),
        };
    }
}
