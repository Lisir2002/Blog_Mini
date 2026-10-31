<?php

declare(strict_types=1);

namespace PenBlog\Notification;

use PenBlog\Repository\SqlDriver;

/**
 * 05 §4 三层偏好:系统默认(NotificationPolicy)< 用户分类设置(本表)。
 * use_default=true 即继承系统默认;静默时段独立于渠道开关,始终按行生效。
 */
final class PreferenceRepository
{
    public const CHANNELS = ['in_app', 'email', 'webhook'];

    public function __construct(private readonly SqlDriver $driver)
    {
    }

    /**
     * @return array{channels: list<string>, quiet: array{start: ?string, end: ?string, tz: ?string}, inherited: bool}
     */
    public function resolve(int $userId, string $category): array
    {
        $row = $this->find($userId, $category);
        $quiet = [
            'start' => isset($row['quiet_start']) && $row['quiet_start'] !== null ? (string) $row['quiet_start'] : null,
            'end' => isset($row['quiet_end']) && $row['quiet_end'] !== null ? (string) $row['quiet_end'] : null,
            'tz' => isset($row['quiet_tz']) && $row['quiet_tz'] !== null ? (string) $row['quiet_tz'] : null,
        ];
        if ($row === null || (int) $row['use_default'] === 1) {
            return ['channels' => NotificationPolicy::defaultChannels($category), 'quiet' => $quiet, 'inherited' => true];
        }
        $channels = array_values(array_filter(
            (array) json_decode((string) ($row['channels_json'] ?? '[]'), true),
            static fn ($ch): bool => in_array((string) $ch, self::CHANNELS, true),
        ));
        return ['channels' => array_map('strval', $channels), 'quiet' => $quiet, 'inherited' => false];
    }

    /** @param array<string, mixed> $settings use_default/channels/quiet_start/quiet_end/quiet_tz */
    public function save(int $userId, string $category, array $settings): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $channels = array_values(array_unique(array_filter(
            (array) ($settings['channels'] ?? []),
            static fn ($ch): bool => in_array((string) $ch, self::CHANNELS, true),
        )));
        $this->driver->upsert(
            'notification_preferences',
            [
                'user_id' => $userId,
                'category' => $category,
                'use_default' => !empty($settings['use_default']) ? 1 : 0,
                'channels_json' => json_encode($channels, JSON_UNESCAPED_UNICODE),
                'quiet_start' => $this->nullableTime($settings['quiet_start'] ?? null),
                'quiet_end' => $this->nullableTime($settings['quiet_end'] ?? null),
                'quiet_tz' => $this->nullableTz($settings['quiet_tz'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['user_id', 'category'],
            ['use_default', 'channels_json', 'quiet_start', 'quiet_end', 'quiet_tz', 'updated_at'],
        );
    }

    /** @return array<string, array<string, mixed>> category => 行(仅用户显式存过的) */
    public function allFor(int $userId): array
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM notification_preferences WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['category']] = $row;
        }
        return $out;
    }

    /** 静默窗口判定(支持跨零点);now 供测试注入。 */
    public static function inQuiet(array $quiet, ?\DateTimeImmutable $now = null): bool
    {
        if (empty($quiet['start']) || empty($quiet['end'])) {
            return false;
        }
        try {
            $tz = new \DateTimeZone((string) ($quiet['tz'] ?? 'UTC'));
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }
        $cur = ($now ?? new \DateTimeImmutable('now', $tz))->setTimezone($tz)->format('H:i');
        $start = (string) $quiet['start'];
        $end = (string) $quiet['end'];
        if ($start === $end) {
            return false;
        }
        return $start < $end
            ? ($cur >= $start && $cur < $end)
            : ($cur >= $start || $cur < $end); // 跨零点,如 22:00→07:00
    }

    /** @return array<string, mixed>|null */
    private function find(int $userId, string $category): ?array
    {
        $stmt = $this->driver->pdo()->prepare(
            'SELECT * FROM notification_preferences WHERE user_id = :u AND category = :c'
        );
        $stmt->execute(['u' => $userId, 'c' => $category]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function nullableTime(mixed $v): ?string
    {
        $s = trim((string) $v);
        return $s !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) ? $s : null;
    }

    private function nullableTz(mixed $v): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        try {
            new \DateTimeZone($s);
        } catch (\Throwable) {
            return null;
        }
        return $s;
    }
}
