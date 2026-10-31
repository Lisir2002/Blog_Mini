<?php

declare(strict_types=1);

namespace PenBlog\Api;

use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Kernel;
use PenBlog\Notification\NotificationPolicy;
use PenBlog\Notification\NotificationRepository;
use PenBlog\Notification\PreferenceRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 05 §7 通知 API:列表/已读/归档/偏好/未读计数/轨迹。
 * 全部 requireAuth;"本人行"语义由仓储按 recipient_id 兜底。
 */
final class NotificationRoutes
{
    public static function register(Kernel $kernel): void
    {
        $c = $kernel->container;
        $router = $kernel->router;
        $repo = static fn (): NotificationRepository => $c->get(NotificationRepository::class);
        $prefs = static fn (): PreferenceRepository => $c->get(PreferenceRepository::class);

        $router->get('/api/v1/notifications', static function (ServerRequestInterface $req) use ($repo): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            $query = $req->getQueryParams();
            $filter = (array) ($query['filter'] ?? []);
            $page = max(1, (int) ($query['page'] ?? 1));
            $size = min(100, max(1, (int) ($query['size'] ?? 20)));
            $result = $repo()->list($gate->user->id(), [
                'category' => isset($filter['category']) ? (string) $filter['category'] : '',
                'unread' => !empty($filter['unread']),
                'archived' => !empty($filter['archived']),
            ], $page, $size);
            return Json::ok(200, [
                'items' => array_map(static fn (array $r): array => self::dto($r), $result['items']),
                'page' => $page,
                'size' => $size,
                'total' => $result['total'],
            ]);
        });

        $router->post('/api/v1/notifications/read-all', static function (ServerRequestInterface $req) use ($repo): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            return Json::ok(200, ['updated' => $repo()->markAllRead($gate->user->id())]);
        });

        $router->get('/api/v1/notifications/unread-count', static function (ServerRequestInterface $req) use ($repo): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            return Json::ok(200, ['count' => $repo()->unreadCount($gate->user->id())]);
        });

        $router->get('/api/v1/notifications/preferences', static function (ServerRequestInterface $req) use ($prefs, $c): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            $items = [];
            foreach (array_keys(NotificationPolicy::all()) as $category) {
                $resolved = $prefs()->resolve($gate->user->id(), $category);
                $items[] = [
                    'category' => $category,
                    'label' => NotificationPolicy::label($category),
                    'defaultChannels' => NotificationPolicy::defaultChannels($category),
                    'channels' => $resolved['channels'],
                    'inherited' => $resolved['inherited'],
                    'quiet' => $resolved['quiet'],
                ];
            }
            return Json::ok(200, [
                'items' => $items,
                'webhook' => [
                    'configured' => (string) $c->get(\PenBlog\Kernel\OptionsStore::class)
                        ->get('notify.webhook.url.' . $gate->user->id(), '') !== '',
                ],
            ]);
        });

        $router->put('/api/v1/notifications/preferences', static function (ServerRequestInterface $req) use ($prefs, $c): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            $body = Body::of($req);
            $category = (string) ($body['category'] ?? '');
            if (!NotificationPolicy::isKnown($category)) {
                throw new ValidationException([['field' => 'category', 'reason' => 'invalid']], "未知分类 {$category}");
            }
            $useDefault = !array_key_exists('use_default', $body) || !empty($body['use_default']);
            $channels = [];
            if (!$useDefault) {
                $channels = (array) ($body['channels'] ?? []);
                foreach ($channels as $ch) {
                    if (!in_array((string) $ch, PreferenceRepository::CHANNELS, true)) {
                        throw new ValidationException([['field' => 'channels', 'reason' => 'invalid']], "未知渠道 {$ch}");
                    }
                }
            }
            foreach (['quiet_start', 'quiet_end'] as $k) {
                $v = (string) ($body[$k] ?? '');
                if ($v !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                    throw new ValidationException([['field' => $k, 'reason' => 'invalid']], "{$k} 需为 HH:MM");
                }
            }
            $prefs()->save($gate->user->id(), $category, [
                'use_default' => $useDefault,
                'channels' => $channels,
                'quiet_start' => $body['quiet_start'] ?? null,
                'quiet_end' => $body['quiet_end'] ?? null,
                'quiet_tz' => $body['quiet_tz'] ?? null,
            ]);
            // webhook 端点配置(一等 options 键,per-user)
            if (isset($body['webhook']) && is_array($body['webhook'])) {
                $options = $c->get(\PenBlog\Kernel\OptionsStore::class);
                $url = trim((string) ($body['webhook']['url'] ?? ''));
                if ($url === '') {
                    $options->delete('notify.webhook.url.' . $gate->user->id());
                } elseif (preg_match('#^https?://#i', $url)) {
                    $options->set('notify.webhook.url.' . $gate->user->id(), $url);
                } else {
                    throw new ValidationException([['field' => 'webhook.url', 'reason' => 'invalid']], 'webhook URL 需为 http(s)');
                }
                $secret = trim((string) ($body['webhook']['secret'] ?? ''));
                if ($secret !== '') {
                    $options->set('notify.webhook.secret.' . $gate->user->id(), $secret);
                }
            }
            return Json::ok(200, ['ok' => true]);
        });

        $router->get('/api/v1/notifications/traces/{eventId}', static function (ServerRequestInterface $req) use ($repo): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $eventId = (string) (Body::routeParams($req)['eventId'] ?? '');
            return Json::ok(200, ['items' => $repo()->trace($eventId)]);
        });

        foreach (['read' => 'markRead', 'archive' => 'archive'] as $verb => $method) {
            $router->post("/api/v1/notifications/{id}:{$verb}", static function (ServerRequestInterface $req) use ($repo, $method): ResponseInterface {
                $gate = AuthMiddleware::gate($req)->requireAuth();
                $id = (int) (Body::routeParams($req)['id'] ?? 0);
                $updated = $repo()->{$method}($id, $gate->user->id());
                if (!$updated && $repo()->find($id) === null) {
                    throw ApiException::notFound('Notification');
                }
                return Json::ok(200, ['ok' => true, 'changed' => $updated]);
            });
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function dto(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'eventId' => (string) $row['event_id'],
            'category' => (string) $row['category'],
            'categoryLabel' => NotificationPolicy::label((string) $row['category']),
            'title' => (string) $row['title'],
            'bodyHtml' => $row['body_html'],
            'link' => (string) ($row['link'] ?? ''),
            'importance' => (string) $row['importance'],
            'status' => (string) $row['status'],
            'channels' => array_map(
                static fn (array $e): string => (string) $e['channel'],
                (array) json_decode((string) ($row['channels_json'] ?? '[]'), true),
            ),
            'results' => (array) json_decode((string) ($row['results_json'] ?? '[]'), true),
            'readAt' => $row['read_at'],
            'archivedAt' => $row['archived_at'],
            'createdAt' => (string) $row['created_at'],
        ];
    }
}
