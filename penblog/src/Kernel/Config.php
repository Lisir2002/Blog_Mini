<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

/**
 * ConfigMap(02 §6):config.php 数组 + 环境变量覆盖。
 * 键 'db.driver' 对应环境变量 PENBLOG_DB_DRIVER。
 */
final class Config
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly array $values,
        private readonly array $env = [],
    ) {
    }

    /** @param array<string, string> $env */
    public static function fromFile(string $path, array $env = []): self
    {
        if (!is_file($path)) {
            throw new NotInstalledException("Config file not found: {$path}");
        }
        return new self(require $path, $env);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $envKey = 'PENBLOG_' . strtoupper(str_replace('.', '_', $key));
        if (isset($this->env[$envKey])) {
            return $this->cast($this->env[$envKey]);
        }
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    private function cast(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => ctype_digit($value) ? (int) $value : $value,
        };
    }
}
