<?php

declare(strict_types=1);

namespace PenBlog\Auth;

use PenBlog\Kernel\Extension;
use PenBlog\Kernel\Metadata;

/**
 * 权限等级 Extension(kind: AccessLevel,09 §1):等级不是硬编码,
 * 头衔/权限集/徽章色可自定义;L0 恒等于全集(通配 `*`,不可删减)。
 */
final class AccessLevel extends Extension
{
    public const KIND = 'AccessLevel';

    /** 09 §1 四级默认定义(安装种子)。 */
    public const DEFAULTS = [
        0 => [
            'name' => 'level-0', 'level' => 0, 'title' => '管理员',
            'title_i18n' => ['en' => 'Administrator'],
            'permissions' => ['*'],
            'color' => '#7C3AED',
        ],
        1 => [
            'name' => 'level-1', 'level' => 1, 'title' => '编辑',
            'title_i18n' => ['en' => 'Editor'],
            'permissions' => ['post:manage', 'page:manage', 'comment:moderate', 'media:create', 'user:read'],
            'color' => '#0E7490',
        ],
        2 => [
            'name' => 'level-2', 'level' => 2, 'title' => '作者',
            'title_i18n' => ['en' => 'Author'],
            'permissions' => ['post:write', 'media:create', 'comment:create'],
            'color' => '#B45309',
        ],
        3 => [
            'name' => 'level-3', 'level' => 3, 'title' => '订阅者',
            'title_i18n' => ['en' => 'Contributor'],
            'permissions' => ['comment:create'],
            'color' => '#57534E',
        ],
    ];

    /** @return list<self> */
    public static function defaultDefinitions(): array
    {
        return array_map(static fn (array $d): self => self::fromSpec($d), self::DEFAULTS);
    }

    /** @param array<string, mixed> $d */
    public static function fromSpec(array $d): self
    {
        return new self(Metadata::generate((string) $d['name'], self::KIND), [
            'level' => (int) $d['level'],
            'title' => (string) $d['title'],
            'title_i18n' => $d['title_i18n'] ?? [],
            'permissions' => array_values((array) $d['permissions']),
            'color' => (string) ($d['color'] ?? '#57534E'),
            'xp' => $d['xp'] ?? ['enabled' => false],
        ]);
    }

    public function level(): int
    {
        return (int) ($this->spec['level'] ?? 3);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return array_values(array_map('strval', (array) ($this->spec['permissions'] ?? [])));
    }

    public function title(): string
    {
        return (string) ($this->spec['title'] ?? "L{$this->level()}");
    }

    public function color(): string
    {
        return (string) ($this->spec['color'] ?? '#57534E');
    }
}
