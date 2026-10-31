<?php

declare(strict_types=1);

namespace PenBlog\Repository;

use PDO;

/**
 * 唯一允许出现方言差异的层(见 01 §5 / 07 §3)。
 */
interface SqlDriver
{
    public function pdo(): PDO;

    public function name(): string;

    public function quoteIdentifier(string $ident): string;

    /** @param array<string, mixed> $data */
    public function insertReturningId(string $table, array $data): int;

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keyCols
     * @param list<string>         $updateCols
     */
    public function upsert(string $table, array $data, array $keyCols, array $updateCols): void;

    public function createTableSql(string $table, Table $def): string;

    /** @return list<string> CREATE TABLE 之后需要执行的语句(索引等) */
    public function afterCreateSql(string $table, Table $def): array;
}
