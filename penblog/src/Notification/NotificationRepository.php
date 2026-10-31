<?php

declare(strict_types=1);

namespace PenBlog\Notification;

use PenBlog\Repository\SqlDriver;

/**
 * notifications 收件表 + notification_events 轨迹日志(05 §8)。
 */
final class NotificationRepository
{
    public function __construct(private readonly SqlDriver $driver)
    {
    }

    /**
     * 幂等插入:unique(event_id, recipient_id) 冲突时返回已有行 id。
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        try {
            return $this->driver->insertReturningId('notifications', $row);
        } catch (\PDOException $e) {
            $stmt = $this->driver->pdo()->prepare(
                'SELECT id FROM notifications WHERE event_id = :e AND recipient_id = :r'
            );
            $stmt->execute(['e' => $row['event_id'], 'r' => $row['recipient_id']]);
            $found = $stmt->fetch();
            if ($found !== false) {
                return (int) $found['id'];
            }
            throw $e;
        }
    }

    /**
     * @param array{category?: string, unread?: bool, archived?: bool} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(int $recipientId, array $filters, int $page, int $size): array
    {
        $where = ['recipient_id = :r'];
        $bind = ['r' => $recipientId];
        if (!empty($filters['category'])) {
            $where[] = 'category = :cat';
            $bind['cat'] = (string) $filters['category'];
        }
        if (!empty($filters['unread'])) {
            $where[] = 'read_at IS NULL';
        }
        $where[] = !empty($filters['archived']) ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
        $whereSql = implode(' AND ', $where);

        $stmt = $this->driver->pdo()->prepare("SELECT COUNT(*) AS c FROM notifications WHERE {$whereSql}");
        $stmt->execute($bind);
        $total = (int) $stmt->fetch()['c'];

        $stmt = $this->driver->pdo()->prepare(
            "SELECT * FROM notifications WHERE {$whereSql} ORDER BY created_at DESC, id DESC
             LIMIT " . $size . ' OFFSET ' . max(0, ($page - 1) * $size)
        );
        $stmt->execute($bind);
        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM notifications WHERE id = :i');
        $stmt->execute(['i' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function unreadCount(int $recipientId): int
    {
        $stmt = $this->driver->pdo()->prepare(
            'SELECT COUNT(*) AS c FROM notifications WHERE recipient_id = :r AND read_at IS NULL AND archived_at IS NULL'
        );
        $stmt->execute(['r' => $recipientId]);
        return (int) $stmt->fetch()['c'];
    }

    public function markRead(int $id, int $recipientId): bool
    {
        $stmt = $this->driver->pdo()->prepare(
            'UPDATE notifications SET read_at = :t, updated_at = :t2 WHERE id = :i AND recipient_id = :r AND read_at IS NULL'
        );
        $stmt->execute(['t' => gmdate('Y-m-d\TH:i:s\Z'), 't2' => gmdate('Y-m-d\TH:i:s\Z'), 'i' => $id, 'r' => $recipientId]);
        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $recipientId): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->driver->pdo()->prepare(
            'UPDATE notifications SET read_at = :t, updated_at = :t WHERE recipient_id = :r AND read_at IS NULL AND archived_at IS NULL'
        );
        $stmt->execute(['t' => $now, 'r' => $recipientId]);
        return $stmt->rowCount();
    }

    public function archive(int $id, int $recipientId): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->driver->pdo()->prepare(
            'UPDATE notifications SET archived_at = :t, updated_at = :t WHERE id = :i AND recipient_id = :r AND archived_at IS NULL'
        );
        $stmt->execute(['t' => $now, 'i' => $id, 'r' => $recipientId]);
        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $fields['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $set = [];
        $bind = ['id' => $id];
        foreach ($fields as $col => $v) {
            $ph = 'v_' . preg_replace('/\W/', '_', (string) $col);
            $set[] = $this->driver->quoteIdentifier((string) $col) . ' = :' . $ph;
            $bind[$ph] = $v;
        }
        $this->driver->pdo()->prepare('UPDATE notifications SET ' . implode(', ', $set) . ' WHERE id = :id')
            ->execute($bind);
    }

    /** 去重合并(05 §3):10 分钟窗口内同 dedup_key 的存活行(同步管线已投递也算)。 @return array<string, mixed>|null */
    public function findOpenByDedupKey(string $dedupKey, int $recipientId, string $since): ?array
    {
        $stmt = $this->driver->pdo()->prepare(
            "SELECT * FROM notifications WHERE dedup_key = :k AND recipient_id = :r AND created_at >= :s
             AND status != 'dead_lettered' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['k' => $dedupKey, 'r' => $recipientId, 's' => $since]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> 待重试行 */
    public function dueForRetry(string $now): array
    {
        $stmt = $this->driver->pdo()->prepare(
            "SELECT * FROM notifications WHERE status IN ('partial','failed')
             AND next_retry_at IS NOT NULL AND next_retry_at <= :n ORDER BY id LIMIT 100"
        );
        $stmt->execute(['n' => $now]);
        return $stmt->fetchAll();
    }

    public function appendTrace(string $eventId, ?int $notificationId, string $stage, ?string $channel, ?string $detail): void
    {
        $this->driver->insertReturningId('notification_events', [
            'event_id' => $eventId,
            'notification_id' => $notificationId,
            'stage' => $stage,
            'channel' => $channel,
            'detail' => $detail,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function trace(string $eventId): array
    {
        $stmt = $this->driver->pdo()->prepare(
            'SELECT * FROM notification_events WHERE event_id = :e ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['e' => $eventId]);
        return $stmt->fetchAll();
    }
}
