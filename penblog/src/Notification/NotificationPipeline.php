<?php

declare(strict_types=1);

namespace PenBlog\Notification;

use PenBlog\Auth\User;
use PenBlog\Auth\UserRepository;
use PenBlog\Notification\Channels\EmailChannel;
use PenBlog\Notification\Channels\WebhookChannel;
use PenBlog\Repository\SqlDriver;

/**
 * 05 §2 五层管线(同步实现,渠道失败走退避重试):
 * ingest → route → render → deliver。trace 全程留痕于 notification_events。
 */
final class NotificationPipeline
{
    private const BACKOFF = [60, 300, 1500, 7200, 28800]; // 第 1..5 次失败后
    private const MAX_ATTEMPTS = 5;

    /** @param array<string, ChannelAdapter> $adapters */
    public function __construct(
        private readonly SqlDriver $driver,
        private readonly UserRepository $users,
        private readonly PreferenceRepository $prefs,
        private readonly NotificationRepository $repo,
        private readonly NotificationTemplates $templates,
        private readonly array $adapters,
    ) {
    }

    /** @return list<int> 本次涉及的通知行 id */
    public function process(NotifyRequest $req): array
    {
        // ---- 1. 接入层 ----
        if (!NotificationPolicy::isKnown($req->category)) {
            $this->repo->appendTrace($req->eventId, null, 'ingest', null, 'unknown_category:' . $req->category);
            return [];
        }
        $recipients = $this->expand($req->recipient);
        if ($recipients === []) {
            $this->repo->appendTrace($req->eventId, null, 'ingest', null, 'no_recipients');
            return [];
        }
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 600);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $ids = [];
        $fresh = [];
        foreach ($recipients as $user) {
            if ($req->dedupKey !== null) {
                $existing = $this->repo->findOpenByDedupKey($req->dedupKey, $user->id(), $since);
                if ($existing !== null) {
                    $this->repo->appendTrace($req->eventId, (int) $existing['id'], 'ingest', null, 'deduped');
                    $ids[] = (int) $existing['id'];
                    continue;
                }
            }
            $id = $this->repo->insert([
                'event_id' => $req->eventId,
                'recipient_id' => $user->id(),
                'category' => $req->category,
                'template_key' => $req->templateKey,
                'title' => '',
                'body_html' => null,
                'link' => $req->link,
                'importance' => $req->importance,
                'status' => 'ingested',
                'dedup_key' => $req->dedupKey,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->repo->appendTrace($req->eventId, $id, 'ingest', null, 'recipient:' . $user->username());
            $ids[] = $id;
            $fresh[] = $id;
        }

        // ---- 2. 路由层 + 3. 渲染层 + 4. 投递层 ----
        foreach ($fresh as $id) {
            $row = $this->repo->find($id);
            if ($row === null) {
                continue;
            }
            $channels = $this->route($row, $req);
            $rendered = $this->renderStage($row, $req, $channels);
            if ($rendered === null) {
                continue; // render_failed 已落状态
            }
            $this->deliver($row, $rendered);
        }
        return $ids;
    }

    /** 重试到期的 partial/failed 行(仅失败渠道)。 @return int 处理条数 */
    public function retryDue(): int
    {
        $rows = $this->repo->dueForRetry(gmdate('Y-m-d\TH:i:s\Z'));
        $done = 0;
        foreach ($rows as $row) {
            $entries = json_decode((string) $row['channels_json'], true) ?: [];
            $results = json_decode((string) ($row['results_json'] ?? '[]'), true) ?: [];
            $byChannel = [];
            foreach ($results as $r) {
                $byChannel[(string) $r['channel']] = $r;
            }
            $user = $this->users->findById((int) $row['recipient_id']);
            if ($user === null) {
                $this->repo->update((int) $row['id'], ['status' => 'dead_lettered', 'next_retry_at' => null]);
                continue;
            }
            $allOk = true;
            foreach ($entries as $entry) {
                $ch = (string) $entry['channel'];
                if ($ch === 'in_app' || ($byChannel[$ch]['ok'] ?? false)) {
                    continue;
                }
                $result = ($this->adapters[$ch] ?? null)?->send($entry, $user)
                    ?? DeliveryResult::failure('no_adapter', false);
                $byChannel[$ch] = ['channel' => $ch, 'ok' => $result->ok, 'error' => $result->error];
                $this->repo->appendTrace((string) $row['event_id'], (int) $row['id'], 'retry', $ch, $result->ok ? 'ok' : $result->error);
                if (!$result->ok) {
                    $allOk = false;
                }
            }
            $fields = ['results_json' => json_encode(array_values($byChannel), JSON_UNESCAPED_UNICODE)];
            if ($allOk) {
                $fields['status'] = 'delivered';
                $fields['next_retry_at'] = null;
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $fields['attempts'] = $attempts;
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $fields['status'] = 'dead_lettered';
                    $fields['next_retry_at'] = null;
                } else {
                    $fields['status'] = 'failed';
                    $fields['next_retry_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + self::BACKOFF[$attempts - 1]);
                }
            }
            $this->repo->update((int) $row['id'], $fields);
            $done++;
        }
        return $done;
    }

    /** @return list<string> 路由后的渠道(in_app 恒在兜底,永不 suppressed) */
    private function route(array $row, NotifyRequest $req): array
    {
        $pref = $this->prefs->resolve((int) $row['recipient_id'], $req->category);
        $channels = $pref['channels'];
        if ($channels === []) {
            $channels = ['in_app'];
        }
        if (!in_array('in_app', $channels, true)) {
            array_unshift($channels, 'in_app');
        }
        if ($req->importance !== 'critical' && PreferenceRepository::inQuiet($pref['quiet'])) {
            $channels = array_values(array_filter($channels, static fn (string $c): bool => $c === 'in_app'));
        }
        $this->repo->update((int) $row['id'], ['status' => 'routed']);
        $this->repo->appendTrace($req->eventId, (int) $row['id'], 'route', null, implode(',', $channels));
        return $channels;
    }

    /**
     * @param list<string> $channels
     * @return list<array{channel: string, title: string, body: string, link: string}>|null null=渲染失败
     */
    private function renderStage(array $row, NotifyRequest $req, array $channels): ?array
    {
        $entries = [];
        foreach ($channels as $ch) {
            $r = $this->templates->render($req->templateKey, $ch, $req->payload);
            if ($r === null) {
                $this->repo->update((int) $row['id'], ['status' => 'render_failed']);
                $this->repo->appendTrace($req->eventId, (int) $row['id'], 'render', $ch, 'unknown_template:' . $req->templateKey);
                return null;
            }
            $entries[] = ['channel' => $ch] + $r;
        }
        $inApp = $entries[array_key_first($entries)] ?? null;
        foreach ($entries as $e) {
            if ($e['channel'] === 'in_app') {
                $inApp = $e;
            }
        }
        $this->repo->update((int) $row['id'], [
            'status' => 'rendered',
            'title' => mb_substr($inApp['title'], 0, 255),
            'body_html' => $inApp['body'],
            'link' => $inApp['link'] !== '' ? $inApp['link'] : $req->link,
            'channels_json' => json_encode($entries, JSON_UNESCAPED_UNICODE),
        ]);
        $this->repo->appendTrace($req->eventId, (int) $row['id'], 'render', null, implode(',', $channels));
        return $entries;
    }

    /** @param list<array{channel: string, title: string, body: string, link: string}> $entries */
    private function deliver(array $row, array $entries): void
    {
        $user = $this->users->findById((int) $row['recipient_id']);
        $results = [];
        $anyFailed = false;
        $anyRetryable = false;
        foreach ($entries as $entry) {
            $ch = (string) $entry['channel'];
            if ($ch === 'in_app') {
                $result = DeliveryResult::success('in_app');
            } elseif ($user === null) {
                $result = DeliveryResult::failure('recipient_gone', false);
            } else {
                $result = ($this->adapters[$ch] ?? null)?->send($entry, $user)
                    ?? DeliveryResult::failure('no_adapter', false);
            }
            $results[] = ['channel' => $ch, 'ok' => $result->ok, 'error' => $result->error];
            $this->repo->appendTrace((string) $row['event_id'], (int) $row['id'], 'deliver', $ch, $result->ok ? 'ok' : $result->error);
            if (!$result->ok) {
                $anyFailed = true;
                $anyRetryable = $anyRetryable || $result->retryable;
            }
        }
        $fields = ['results_json' => json_encode($results, JSON_UNESCAPED_UNICODE)];
        if (!$anyFailed) {
            $fields['status'] = 'delivered';
        } elseif ($anyRetryable) {
            $attempts = (int) $row['attempts'] + 1;
            $fields['attempts'] = $attempts;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $fields['status'] = 'dead_lettered';
            } else {
                $fields['status'] = 'partial';
                $fields['next_retry_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + self::BACKOFF[$attempts - 1]);
            }
        } else {
            $fields['status'] = 'partial'; // 不可重试的失败留痕,不进重试队列
        }
        $this->repo->update((int) $row['id'], $fields);
    }

    /** @return list<User> */
    private function expand(string|int $recipient): array
    {
        if (is_int($recipient)) {
            $user = $this->users->findById($recipient);
            return $user !== null && $user->status() === 'active' ? [$user] : [];
        }
        $sql = match ($recipient) {
            'role:admin' => 'SELECT id FROM users WHERE level = 0 AND status = \'active\'',
            'role:manager' => 'SELECT id FROM users WHERE level <= 1 AND status = \'active\'',
            'broadcast' => 'SELECT id FROM users WHERE status = \'active\'',
            default => null,
        };
        if ($sql === null) {
            return [];
        }
        $ids = array_column($this->driver->pdo()->query($sql)->fetchAll(), 'id');
        return array_values(array_filter(
            $this->users->findManyByIds(array_map('intval', $ids)),
            static fn (User $u): bool => $u->status() === 'active',
        ));
    }
}
