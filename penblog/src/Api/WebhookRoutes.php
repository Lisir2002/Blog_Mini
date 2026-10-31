<?php

declare(strict_types=1);

namespace PenBlog\Api;

use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Kernel;
use PenBlog\Webhook\WebhookDispatcher;
use PenBlog\Webhook\WebhookRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 06 §8 Webhook 出站管理 API(全部需 system:manage)。secret 明文仅创建时返回一次。
 */
final class WebhookRoutes
{
    public static function register(Kernel $kernel): void
    {
        $c = $kernel->container;
        $router = $kernel->router;
        $hooks = static fn (): WebhookRepository => $c->get(WebhookRepository::class);
        $dispatcher = static fn (): WebhookDispatcher => $c->get(WebhookDispatcher::class);

        $router->post('/api/v1/webhooks', static function (ServerRequestInterface $req) use ($hooks): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->require('system:manage');
            $body = Body::of($req);
            $url = trim((string) ($body['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                throw new ValidationException([['field' => 'url', 'reason' => 'invalid']], 'url 必须以 http(s):// 开头');
            }
            $events = $body['events'] ?? null;
            if (!is_array($events) || $events === []) {
                throw ValidationException::required('events');
            }
            $events = array_values(array_unique(array_map('strval', $events)));
            foreach ($events as $ev) {
                if ($ev !== '*' && !in_array($ev, WebhookDispatcher::EVENTS, true)) {
                    throw new ValidationException([['field' => 'events', 'reason' => 'unknown_event']], "未知事件 {$ev}");
                }
            }
            $secret = trim((string) ($body['secret'] ?? ''));
            if ($secret === '') {
                $secret = 'whsec_' . bin2hex(random_bytes(20));
            }
            $id = $hooks()->create($url, $secret, $events, $gate->user->id());
            return Json::ok(201, ['webhook' => [
                'id' => $id, 'url' => $url, 'events' => $events, 'secret' => $secret, 'active' => true,
            ]]);
        });

        $router->get('/api/v1/webhooks', static function (ServerRequestInterface $req) use ($hooks): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $items = array_map(self::dto(...), $hooks()->all());
            return Json::ok(200, ['items' => $items]);
        });

        $router->delete('/api/v1/webhooks/{id}', static function (ServerRequestInterface $req) use ($hooks): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $id = (int) (Body::routeParams($req)['id'] ?? 0);
            if (!$hooks()->delete($id)) {
                throw ApiException::notFound('Webhook');
            }
            return Json::ok(200, ['ok' => true]);
        });

        $router->get('/api/v1/webhooks/{id}/deliveries', static function (ServerRequestInterface $req) use ($hooks): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $id = (int) (Body::routeParams($req)['id'] ?? 0);
            if ($hooks()->find($id) === null) {
                throw ApiException::notFound('Webhook');
            }
            return Json::ok(200, ['items' => $hooks()->recentDeliveries($id)]);
        });

        $router->post('/api/v1/webhooks/{id}:test', static function (ServerRequestInterface $req) use ($dispatcher): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $id = (int) (Body::routeParams($req)['id'] ?? 0);
            $deliveryId = $dispatcher()->test($id);
            return Json::ok(200, ['delivery' => $deliveryId, 'ok' => true]);
        });
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function dto(array $row): array
    {
        $secret = (string) $row['secret'];
        return [
            'id' => (int) $row['id'],
            'url' => (string) $row['url'],
            'events' => json_decode((string) $row['events_json'], true) ?: [],
            'active' => (bool) $row['active'],
            'secretPreview' => substr($secret, 0, 10) . '…',
            'createdAt' => (string) $row['created_at'],
        ];
    }
}
