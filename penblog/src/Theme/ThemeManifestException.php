<?php

declare(strict_types=1);

namespace PenBlog\Theme;

/**
 * theme.json 校验失败(03 §3):携带全部错误项,便于后台展示。
 */
final class ThemeManifestException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors, public readonly string $source)
    {
        parent::__construct("Invalid theme manifest {$source}: " . implode('; ', $errors));
    }
}
