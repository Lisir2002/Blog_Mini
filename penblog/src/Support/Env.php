<?php

declare(strict_types=1);

namespace PenBlog\Support;

final class Env
{
    /** 解析 .env 文件为数组;不修改进程环境变量。 */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && $value[0] === substr($value, -1)) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
