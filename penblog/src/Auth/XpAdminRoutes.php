<?php

declare(strict_types=1);

namespace PenBlog\Auth;

use Nyholm\Psr7\Response;
use PenBlog\Api\ApiException;
use PenBlog\Api\Body;
use PenBlog\Api\Json;
use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Kernel;
use PenBlog\Notification\NotifyRequest;
use PenBlog\Theme\ThemeRoutes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 经验系统管理面(09 §2/§8):/api/v1/xp/settings(system:manage)、
 * /api/v1/users/{name}/xp:grant(手工发放,幂等)、后台 /admin/xp 页。
 */
final class XpAdminRoutes
{
    public static function register(Kernel $kernel): void
    {
        $router = $kernel->router;
        $xp = static fn (): XpService => $kernel->container->get(XpService::class);

        // ---- 管理 API ----
        $router->get('/api/v1/xp/settings', static function (ServerRequestInterface $req) use ($xp): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            return Json::ok(200, ['settings' => $xp()->settings()]);
        });

        $router->put('/api/v1/xp/settings', static function (ServerRequestInterface $req) use ($xp): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $body = Body::of($req);
            $settings = $xp()->saveSettings([
                'enabled' => $body['enabled'] ?? null,
                'rules' => $body['rules'] ?? null,
                'thresholds' => $body['thresholds'] ?? null,
            ]);
            return Json::ok(200, ['settings' => $settings]);
        });

        $router->post('/api/v1/users/{name}/xp:grant', static function (ServerRequestInterface $req) use ($xp, $kernel): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            $gate->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $user = $kernel->container->get(UserRepository::class)->findByUsername($name)
                ?? throw ApiException::notFound('User');
            $body = Body::of($req);
            $metric = (string) ($body['metric'] ?? '');
            $amount = (int) ($body['amount'] ?? 1);
            $eventId = (string) ($body['eventId'] ?? NotifyRequest::uuid());
            $result = $xp()->grant($user->id(), $metric, $amount, $eventId);
            $fresh = $kernel->container->get(UserRepository::class)->findById($user->id());
            return Json::ok(200, [
                'grant' => $result,
                'user' => ['username' => $name, 'level' => $fresh?->level(), 'xp' => $fresh?->xp()],
            ]);
        });

        // ---- 后台页(system:manage)----
        $manageGate = static function (ServerRequestInterface $req): ?ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            if (!$gate->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/login']);
            }
            if (!$gate->allows('system:manage')) {
                return new Response(302, ['Location' => '/admin/dashboard?error=forbidden']);
            }
            return null;
        };

        $router->get('/admin/xp', static function (ServerRequestInterface $req) use ($kernel, $xp, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            return self::adminPage($kernel, $req, $xp(), $req->getQueryParams());
        });

        $router->post('/admin/xp', static function (ServerRequestInterface $req) use ($xp, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            $body = Body::of($req);
            $rulesRaw = trim((string) ($body['rules'] ?? ''));
            if ($rulesRaw === '') {
                $rules = null; // 留空 → 默认规则(saveSettings 内处理)
            } else {
                $rules = json_decode($rulesRaw, true);
                if (!is_array($rules)) {
                    return new Response(302, ['Location' => '/admin/xp?error=rules_json']);
                }
            }
            $thresholds = [];
            foreach (['1', '2'] as $level) {
                $value = (string) ($body['threshold_' . $level] ?? '');
                if ($value !== '' && ctype_digit($value)) {
                    $thresholds[$level] = (int) $value;
                }
            }
            try {
                $xp()->saveSettings([
                    'enabled' => isset($body['enabled']),
                    'rules' => $rules,
                    'thresholds' => $thresholds !== [] ? $thresholds : null,
                ]);
                return new Response(302, ['Location' => '/admin/xp?ok=1']);
            } catch (ApiException $e) {
                return new Response(302, ['Location' => '/admin/xp?error=' . rawurlencode($e->errorCode)]);
            }
        });
    }

    /** @param array<string, mixed> $query */
    private static function adminPage(Kernel $kernel, ServerRequestInterface $req, XpService $xp, array $query): ResponseInterface
    {
        $settings = $xp->settings();
        return ThemeRoutes::renderAdmin($kernel, $req, 'admin_xp', [
            'settings' => $settings,
            'rulesJson' => json_encode($settings['rules'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'metrics' => XpService::METRICS,
            'notice' => isset($query['ok']) ? '经验系统设置已保存' : null,
            'failure' => match (true) {
                isset($query['error']) && $query['error'] === 'rules_json' => '保存失败:规则 JSON 无法解析',
                isset($query['error']) => '保存失败:' . (string) $query['error'],
                default => null,
            },
        ], 'xp', '经验 · 后台');
    }
}
