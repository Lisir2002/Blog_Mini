<?php

declare(strict_types=1);

namespace PenBlog\Repository;

final class Schema
{
    public function __construct(private readonly SqlDriver $driver)
    {
    }

    /** @param callable(Table): void $definition */
    public function create(string $table, callable $definition): void
    {
        $def = new Table();
        $definition($def);
        $this->driver->pdo()->exec($this->driver->createTableSql($table, $def));
        foreach ($this->driver->afterCreateSql($table, $def) as $sql) {
            $this->driver->pdo()->exec($sql);
        }
    }

    public function drop(string $table): void
    {
        $this->driver->pdo()->exec('DROP TABLE IF EXISTS ' . $this->driver->quoteIdentifier($table));
    }

    public function raw(string $sql): void
    {
        $this->driver->pdo()->exec($sql);
    }
}
