<?php

declare(strict_types=1);

namespace PenBlog\Repository;

abstract class AbstractDriver implements SqlDriver
{
    abstract public function name(): string;

    public function quoteIdentifier(string $ident): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
            throw new \InvalidArgumentException("Illegal identifier: {$ident}");
        }
        return '"' . $ident . '"';
    }

    public function insertReturningId(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $cols)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)),
        );
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($data);
        return (int) $this->pdo()->lastInsertId();
    }

    public function createTableSql(string $table, Table $def): string
    {
        $lines = [];
        foreach ($def->columns as $col) {
            $lines[] = '  ' . $this->renderColumn($col['name'], $col['type'], $col['opts']);
        }
        foreach ($def->checks as $expr) {
            $lines[] = '  CHECK (' . $expr . ')';
        }
        return sprintf(
            "CREATE TABLE %s (\n%s\n)%s",
            $this->quoteIdentifier($table),
            implode(",\n", $lines),
            $this->tableSuffix(),
        );
    }

    public function afterCreateSql(string $table, Table $def): array
    {
        $out = [];
        foreach ($def->indexes as $idx) {
            $cols = implode(', ', array_map($this->quoteIdentifier(...), $idx['cols']));
            $name = $idx['name'] ?? ($table . '_' . implode('_', $idx['cols']) . ($idx['unique'] ? '_uq' : '_idx'));
            $out[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $idx['unique'] ? 'UNIQUE ' : '',
                $this->quoteIdentifier($name),
                $this->quoteIdentifier($table),
                $cols,
            );
        }
        return $out;
    }

    protected function tableSuffix(): string
    {
        return '';
    }

    /** @param array<string, mixed> $opts */
    protected function renderColumn(string $name, string $type, array $opts): string
    {
        $sql = $this->quoteIdentifier($name) . ' ' . $this->renderType($type, $opts);

        if ($type === 'pk') {
            return $sql; // pk 自带 PRIMARY KEY
        }
        if (!($opts['nullable'] ?? false)) {
            $sql .= ' NOT NULL';
        }
        if (array_key_exists('default', $opts)) {
            $sql .= ' DEFAULT ' . $this->renderDefault($opts['default']);
        }
        if ($opts['unique'] ?? false) {
            $sql .= ' UNIQUE';
        }
        if (isset($opts['references'])) {
            [$tbl, $col] = $opts['references'];
            $sql .= sprintf(
                ' REFERENCES %s(%s)%s',
                $this->quoteIdentifier($tbl),
                $this->quoteIdentifier($col),
                isset($opts['onDelete']) ? ' ON DELETE ' . strtoupper($opts['onDelete']) : '',
            );
        }
        return $sql;
    }

    /** @param array<string, mixed> $opts */
    abstract protected function renderType(string $type, array $opts): string;

    abstract protected function renderDefault(mixed $value): string;
}
