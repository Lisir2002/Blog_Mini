<?php

declare(strict_types=1);

namespace PenBlog\Repository;

/**
 * 表结构 DSL:收集与方言无关的列定义,由 Driver 渲染 DDL。
 */
final class Table
{
    /** @var list<array{name:string, type:string, opts:array<string,mixed>}> */
    public array $columns = [];

    /** @var list<array{cols:list<string>, unique:bool, name:?string}> */
    public array $indexes = [];

    /** @var list<string> */
    public array $checks = [];

    public function id(string $name = 'id'): void
    {
        $this->columns[] = ['name' => $name, 'type' => 'pk', 'opts' => []];
    }

    /** @param array<string, mixed> $opts nullable|default|unique */
    public function column(string $name, string $type, array $opts = []): void
    {
        $this->columns[] = ['name' => $name, 'type' => $type, 'opts' => $opts];
    }

    public function string(string $name, int $length = 255, array $opts = []): void
    {
        $this->column($name, 'string', ['length' => $length] + $opts);
    }

    public function text(string $name, array $opts = []): void
    {
        $this->column($name, 'text', $opts);
    }

    public function integer(string $name, array $opts = []): void
    {
        $this->column($name, 'integer', $opts);
    }

    /** 外键列用:MySQL 需与 pk(BIGINT UNSIGNED)同型,SQLite 即 INTEGER。 */
    public function bigInteger(string $name, array $opts = []): void
    {
        $this->column($name, 'bigint', $opts);
    }

    public function boolean(string $name, array $opts = []): void
    {
        $this->column($name, 'boolean', $opts);
    }

    public function timestamp(string $name, array $opts = []): void
    {
        $this->column($name, 'timestamp', $opts);
    }

    public function json(string $name, array $opts = []): void
    {
        $this->column($name, 'json', $opts);
    }

    /** @param list<string> $cols */
    public function index(array $cols, ?string $name = null): void
    {
        $this->indexes[] = ['cols' => $cols, 'unique' => false, 'name' => $name];
    }

    /** @param list<string> $cols */
    public function unique(array $cols, ?string $name = null): void
    {
        $this->indexes[] = ['cols' => $cols, 'unique' => true, 'name' => $name];
    }

    public function check(string $expr): void
    {
        $this->checks[] = $expr;
    }
}
