<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

use PenBlog\Repository\SqlDriver;

/**
 * Options 存储(02 §6):JSON 编码值,命名空间键(如 theme.default.layout)。
 */
final class OptionsStore
{
    public function __construct(private readonly SqlDriver $driver)
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $stmt = $this->driver->pdo()->prepare('SELECT value FROM options WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if ($row === false) {
            return $default;
        }
        return json_decode((string) $row['value'], true);
    }

    public function set(string $name, mixed $value): void
    {
        $this->driver->upsert(
            'options',
            ['name' => $name, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')],
            ['name'],
            ['value', 'updated_at'],
        );
    }

    public function delete(string $name): void
    {
        $stmt = $this->driver->pdo()->prepare('DELETE FROM options WHERE name = :name');
        $stmt->execute(['name' => $name]);
    }

    /** @return array<string, mixed> */
    public function allWithPrefix(string $prefix): array
    {
        $stmt = $this->driver->pdo()->prepare('SELECT name, value FROM options WHERE name LIKE :p ORDER BY name');
        $stmt->execute(['p' => $prefix . '%']);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['name']] = json_decode((string) $row['value'], true);
        }
        return $out;
    }
}
