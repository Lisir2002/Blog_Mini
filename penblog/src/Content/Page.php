<?php

declare(strict_types=1);

namespace PenBlog\Content;

use PenBlog\Kernel\Extension;

/**
 * 独立页面 Extension(kind: Page,07 §2):无投影表,metadata.name 即 slug。
 */
final class Page extends Extension
{
    public const KIND = 'Page';

    public function slug(): string
    {
        return $this->metadata->name;
    }

    public function title(): string
    {
        return (string) ($this->spec['title'] ?? '');
    }

    public function bodyMd(): string
    {
        return (string) ($this->spec['bodyMd'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->spec['status'] ?? 'draft');
    }

    public function isPublished(): bool
    {
        return $this->status() === 'published';
    }
}
