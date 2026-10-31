<?php

declare(strict_types=1);

namespace PenBlog\Webhook;

use PenBlog\Repository\SqlDriver;

/**
 * 06 §8 Webhook 订阅 + 投递日志数据访问。
 */
final class WebhookRepository
{
    public function __construct(private readonly SqlDriver $driver)
    {
    }

    /** @param list<string> $events @return int 新行 id */
    public function create(string $url, string $secret, array $events, ?int $userId): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return $this->driver->insertReturningId('webhooks', [
            'url' => $url,
            'secret' => $secret,
            'events_json' => json_encode(array_values($events), JSON_UNESCAPED_UNICODE),
            'active' => true,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->driver->pdo()->query('SELECT * FROM webhooks ORDER BY id')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM webhooks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** 订阅了 $event(或 "*")的启用 Webhook。 @return list<array<string, mixed>> */
    public function activeFor(string $event): array
    {
        $rows = $this->driver->pdo()
            ->query('SELECT * FROM webhooks WHERE active = 1 ORDER BY id')
            ->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $events = json_decode((string) $row['events_json'], true) ?: [];
            if (in_array('*', $events, true) || in_array($event, $events, true)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[$k] = $v;
        }
        $stmt = $this->driver->pdo()->prepare('UPDATE webhooks SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->driver->pdo()->prepare('DELETE FROM webhooks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** @return int 投递日志行 id */
    public function insertDelivery(int $webhookId, string $event, string $eventId, string $payload): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return $this->driver->insertReturningId('webhook_deliveries', [
            'webhook_id' => $webhookId,
            'event' => $event,
            'event_id' => $eventId,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $fields */
    public function updateDelivery(int $id, array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[$k] = $v;
        }
        $stmt = $this->driver->pdo()->prepare('UPDATE webhook_deliveries SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /** @return array<string, mixed>|null */
    public function findDelivery(int $id): ?array
    {
        $stmt = $this->driver->pdo()->prepare('SELECT * FROM webhook_deliveries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function dueDeliveries(string $now): array
    {
        $stmt = $this->driver->pdo()->prepare(
            "SELECT * FROM webhook_deliveries
             WHERE status = 'failed' AND next_retry_at IS NOT NULL AND next_retry_at <= :n
             ORDER BY id LIMIT 100"
        );
        $stmt->execute(['n' => $now]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> 最近投递记录(不含 payload) */
    public function recentDeliveries(int $webhookId, int $limit = 50): array
    {
        $stmt = $this->driver->pdo()->prepare(
            'SELECT id, webhook_id, event, event_id, status, attempts, response_code, error, next_retry_at, created_at, updated_at
             FROM webhook_deliveries WHERE webhook_id = :w ORDER BY id DESC LIMIT ' . max(1, min($limit, 200))
        );
        $stmt->execute(['w' => $webhookId]);
        return $stmt->fetchAll();
    }
}
