<?php

declare(strict_types=1);

namespace PenBlog\Install;

/**
 * 环境自检(10 §2):必选项失败阻断安装;建议项仅提示。
 */
final class EnvironmentChecker
{
    /**
     * @param array<string, mixed> $dbConfig
     * @return list<array{item: string, ok: bool, required: bool, hint: string}>
     */
    public function check(array $dbConfig, string $rootDir): array
    {
        $driver = (string) ($dbConfig['driver'] ?? 'sqlite');
        $rows = [
            $this->row('PHP >= 8.2', PHP_VERSION_ID >= 80200, true, '当前 ' . PHP_VERSION),
            $this->row('ext-json', extension_loaded('json'), true, 'JSON 序列化必需'),
            $this->row('ext-mbstring', extension_loaded('mbstring'), true, 'Slug/摘要截断必需'),
            $this->row('ext-pdo', extension_loaded('pdo'), true, '数据库访问层'),
            $this->row("pdo_{$driver}", in_array($driver, ['mysql', 'sqlite'], true) && extension_loaded('pdo_' . $driver), true, '所选数据库驱动'),
            $this->row('storage 可写', is_writable($rootDir . '/storage') || $this->mkdirWritable($rootDir . '/storage'), true, '数据库文件与日志'),
            $this->row('config 可写', is_writable($rootDir . '/config') || $this->mkdirWritable($rootDir . '/config'), true, '写入 config.php'),
            $this->row('Zend OPcache', function_exists('opcache_get_status'), false, '建议开启以提升性能'),
        ];
        return $rows;
    }

    /** @return list<string> 必选失败项名称 */
    public function requiredFailures(array $dbConfig, string $rootDir): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['item'],
            array_filter($this->check($dbConfig, $rootDir), static fn (array $r): bool => $r['required'] && !$r['ok']),
        ));
    }

    private function row(string $item, bool $ok, bool $required, string $hint): array
    {
        return ['item' => $item, 'ok' => $ok, 'required' => $required, 'hint' => $hint];
    }

    private function mkdirWritable(string $dir): bool
    {
        return !is_dir($dir) ? @mkdir($dir, 0750, true) && is_writable($dir) : is_writable($dir);
    }
}
