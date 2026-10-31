<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

/**
 * manifest 校验失败(04 §1):携带全部错误项,便于后台展示。
 */
final class ManifestException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors, string $source)
    {
        parent::__construct("Invalid manifest {$source}: " . implode('; ', $errors));
    }
}
