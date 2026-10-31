<?php

declare(strict_types=1);

namespace PenBlog\Notification;

/**
 * 05 §1 分类体系:枚举 + 系统默认渠道(三层偏好的最底层)。
 * 插件可经 register() 注册新分类(须声明默认策略)。
 */
final class NotificationPolicy
{
    /** @var array<string, array{label: string, channels: list<string>}> */
    private static array $categories = [
        'comment' => ['label' => '互动', 'channels' => ['in_app', 'email']],
        'system' => ['label' => '系统', 'channels' => ['in_app']],
        'content' => ['label' => '内容', 'channels' => ['in_app', 'webhook']],
        'digest' => ['label' => '摘要', 'channels' => ['email']],
    ];

    public static function register(string $category, string $label, array $channels): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,15}$/', $category)) {
            throw new \InvalidArgumentException("非法分类名: {$category}");
        }
        self::$categories[$category] = ['label' => $label, 'channels' => array_values($channels)];
    }

    public static function isKnown(string $category): bool
    {
        return isset(self::$categories[$category]);
    }

    /** @return array<string, array{label: string, channels: list<string>}> */
    public static function all(): array
    {
        return self::$categories;
    }

    /** @return list<string> */
    public static function defaultChannels(string $category): array
    {
        return self::$categories[$category]['channels'] ?? ['in_app'];
    }

    public static function label(string $category): string
    {
        return self::$categories[$category]['label'] ?? $category;
    }
}
