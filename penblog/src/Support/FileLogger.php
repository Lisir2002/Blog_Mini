<?php

declare(strict_types=1);

namespace PenBlog\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 文件日志(01 §6):错误页与日志分离;secret 值经调用方保证不入日志。
 */
final class FileLogger extends AbstractLogger
{
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function __construct(
        private readonly string $path,
        private readonly string $minLevel = 'debug',
    ) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (array_search((string) $level, self::LEVELS, true) < array_search($this->minLevel, self::LEVELS, true)) {
            return;
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            strtoupper((string) $level),
            (string) $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE),
        );
        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
