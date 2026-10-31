<?php

declare(strict_types=1);

namespace PenBlog\Theme;

/**
 * 主题模板渲染(03):PHP 模板 + 强制转义助手。
 * 模板内所有动态输出必须走 View::esc(),XSS 红线(08 §1)。
 */
final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $path, array $data = []): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Template not found: {$path}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }

    public static function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 属性/文本通用安全输出。 */
    public static function e(mixed $value): string
    {
        return self::esc($value);
    }
}
