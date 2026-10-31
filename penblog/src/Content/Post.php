<?php

declare(strict_types=1);

namespace PenBlog\Content;

use PenBlog\Kernel\Extension;

/**
 * 文章 Extension(kind: Post,07 §2):文档本体在 extensions,
 * posts 投影表供列表查询;metadata.name 即 slug。
 */
final class Post extends Extension
{
    public const KIND = 'Post';

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

    public function excerpt(): ?string
    {
        return isset($this->spec['excerpt']) ? (string) $this->spec['excerpt'] : null;
    }

    public function status(): string
    {
        return (string) ($this->spec['status'] ?? 'draft');
    }

    public function author(): string
    {
        return (string) ($this->spec['author'] ?? '');
    }

    public function publishedAt(): ?string
    {
        return isset($this->spec['publishedAt']) ? (string) $this->spec['publishedAt'] : null;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_values(array_map('strval', (array) ($this->spec['categories'] ?? [])));
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values(array_map('strval', (array) ($this->spec['tags'] ?? [])));
    }

    public function isPublished(): bool
    {
        return $this->status() === 'published';
    }

    /** 主题渲染用摘要 DTO(不含全文)。 */
    public function teaserDto(): array
    {
        return [
            'slug' => $this->slug(),
            'title' => $this->title(),
            'excerpt' => $this->excerpt(),
            'author' => $this->author(),
            'publishedAt' => $this->publishedAt(),
            'categories' => $this->categories(),
            'tags' => $this->tags(),
        ];
    }
}
