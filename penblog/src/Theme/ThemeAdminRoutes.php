<?php

declare(strict_types=1);

namespace PenBlog\Theme;

use Nyholm\Psr7\Response;
use PenBlog\Api\ApiException;
use PenBlog\Api\Body;
use PenBlog\Api\Json;
use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Kernel;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 主题管理面(03 §7):/api/v1/themes(system:manage)+ 后台 /admin/themes 页。
 */
final class ThemeAdminRoutes
{
    public static function register(Kernel $kernel): void
    {
        $router = $kernel->router;
        $manager = static fn (): ThemeManager => $kernel->theme();

        // ---- 管理 API(全部 system:manage)----
        $router->get('/api/v1/themes', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            return Json::ok(200, [
                'items' => $manager()->list(),
                'errors' => $manager()->discoveryErrors(),
                'active' => $manager()->active(),
            ]);
        });

        $router->post('/api/v1/themes/{name}:activate', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $manager()->activate($name);
            return Json::ok(200, ['theme' => self::row($manager(), $name)]);
        });

        $router->post('/api/v1/themes/{name}:deactivate', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $manager()->deactivate($name);
            return Json::ok(200, ['theme' => self::row($manager(), $name)]);
        });

        $router->delete('/api/v1/themes/{name}', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $manager()->uninstall($name);
            return Json::ok(200, ['ok' => true]);
        });

        $router->get('/api/v1/themes/{name}/settings', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            return Json::ok(200, [
                'schema' => $manager()->settingsSchema($name),
                'values' => $manager()->settingValues($name),
            ]);
        });

        $router->put('/api/v1/themes/{name}/settings', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $manager()->saveSettings($name, Body::of($req));
            return Json::ok(200, ['values' => $manager()->settingValues($name)]);
        });

        // ---- 后台主题页(03 §7)----
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

        $router->get('/admin/themes', static function (ServerRequestInterface $req) use ($kernel, $manager, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            return self::adminPage($kernel, $req, $manager(), Body::of($req));
        });

        foreach (['activate', 'uninstall'] as $action) {
            $router->post("/admin/themes/{name}/{$action}", static function (ServerRequestInterface $req) use ($kernel, $manager, $manageGate, $action): ResponseInterface {
                if (($r = $manageGate($req)) !== null) {
                    return $r;
                }
                $name = (string) (Body::routeParams($req)['name'] ?? '');
                try {
                    $manager()->{$action}($name);
                    return new Response(302, ['Location' => '/admin/themes?ok=' . rawurlencode("{$name}:{$action}")]);
                } catch (ApiException $e) {
                    return new Response(302, ['Location' => '/admin/themes?error=' . rawurlencode($e->errorCode)]);
                }
            });
        }

        $router->post('/admin/themes/{name}/settings', static function (ServerRequestInterface $req) use ($kernel, $manager, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            try {
                $manager()->saveSettings($name, Body::of($req));
                return new Response(302, ['Location' => '/admin/themes?ok=settings']);
            } catch (ApiException $e) {
                return new Response(302, ['Location' => '/admin/themes?error=' . rawurlencode($e->errorCode)]);
            }
        });
    }

    /** @param array<string, mixed> $query */
    private static function adminPage(Kernel $kernel, ServerRequestInterface $req, ThemeManager $manager, array $query): ResponseInterface
    {
        $active = $manager->active();
        try {
            $schema = $manager->settingsSchema($active);
            $values = $manager->settingValues($active);
        } catch (ApiException) {
            $schema = []; // 激活主题损坏:fallback 渲染,不阻断后台
            $values = [];
        }
        return ThemeRoutes::renderAdmin($kernel, $req, 'admin_themes', [
            'themes' => $manager->list(),
            'errors' => $manager->discoveryErrors(),
            'active' => $active,
            'schema' => $schema,
            'values' => $values,
            'notice' => isset($query['ok']) ? '操作成功:' . (string) $query['ok'] : null,
            'failure' => isset($query['error']) ? '操作失败:' . (string) $query['error'] : null,
        ], 'themes', '主题 · 后台');
    }

    /** @return array<string, mixed> */
    private static function row(ThemeManager $manager, string $name): array
    {
        foreach ($manager->list() as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }
        throw ApiException::notFound('Theme');
    }
}
