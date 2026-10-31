<?php

declare(strict_types=1);

namespace PenBlog\Repository;

/**
 * 迁移器(07 §5):版本账本 + 有序应用,每个迁移一个事务。
 */
final class Migrator
{
    /** @var array<string, Migration> version => migration(按 version 排序应用) */
    private array $migrations = [];

    public function __construct(private readonly SqlDriver $driver)
    {
        $this->driver->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations ('
            . $this->driver->quoteIdentifier('version') . ' VARCHAR(191) NOT NULL PRIMARY KEY,'
            . $this->driver->quoteIdentifier('applied_at') . ' VARCHAR(32) NOT NULL'
            . ')'
        );
    }

    public function register(string $version, Migration $migration): void
    {
        $this->migrations[$version] = $migration;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $rows = $this->driver->pdo()->query('SELECT version FROM migrations ORDER BY version')->fetchAll();
        return array_map(static fn (array $r): string => (string) $r['version'], $rows);
    }

    /** @return list<string> 本次新应用的版本 */
    public function run(): array
    {
        $done = $this->applied();
        $pending = array_diff(array_keys($this->migrations), $done);
        sort($pending);

        $ran = [];
        $schema = new Schema($this->driver);
        foreach ($pending as $version) {
            $pdo = $this->driver->pdo();
            $pdo->beginTransaction();
            try {
                $this->migrations[$version]->up($schema);
                $stmt = $pdo->prepare('INSERT INTO migrations (version, applied_at) VALUES (:v, :t)');
                $stmt->execute(['v' => $version, 't' => gmdate('Y-m-d\TH:i:s\Z')]);
                $pdo->commit();
                $ran[] = $version;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new \RuntimeException("Migration {$version} failed: {$e->getMessage()}", 0, $e);
            }
        }
        return $ran;
    }
}
