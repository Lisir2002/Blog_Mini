<?php

declare(strict_types=1);

namespace PenBlog\Support;

final class Slugger
{
    /** 保留 Unicode 字母/数字(含中文),其余折叠为连字符。 */
    public static function fromTitle(string $title): string
    {
        $slug = mb_strtolower(trim($title), 'UTF-8');
        // 转义 URL 不安全字符:仅保留 \p{L}\p{N} 与连字符/下划线
        $slug = preg_replace('/[^\p{L}\p{N}\-_]+/u', '-', $slug) ?? '';
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
        return $slug === '' ? 'post' : $slug;
    }

    /** 同名冲突时追加 -2, -3 … */
    public static function unique(string $slug, callable $exists): string
    {
        if (!$exists($slug)) {
            return $slug;
        }
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $slug . '-' . $i;
            if (!$exists($candidate)) {
                return $candidate;
            }
        }
        return $slug . '-' . bin2hex(random_bytes(4));
    }
}
