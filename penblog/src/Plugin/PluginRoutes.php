<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

use Nyholm\Psr7\Response;
use PenBlog\Api\ApiException;
use PenBlog\Api\Body;
use PenBlog\Api\Json;
use PenBlog\Auth\Gate;
use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Kernel;
use PenBlog\Theme\ThemeRoutes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 插件管理面(04 §8):/api/v1/plugins(system:manage)+ /plugin-assets/{name}/
 * 静态资源白名单路由 + 后台 /admin/plugins 页。
 */
final class PluginRoutes
{
    public static function register(Kernel $kernel): void
    {
        $router = $kernel->router;
        $manager = static fn (): PluginManager => $kernel->plugins();

        // ---- 插件静态资源(04 §5:禁止直接暴露插件目录)----
        $router->get('/plugin-assets/{name}/{file}', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            $params = Body::routeParams($req);
            $name = (string) ($params['name'] ?? '');
            $file = (string) ($params['file'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $name)
                || !preg_match('/^[a-z0-9_-]+\.(css|js|svg|png|jpg|woff2)$/', $file)) {
                return new Response(404);
            }
            $path = $manager()->pluginsDir() . '/' . $name . '/assets/' . $file;
            $real = realpath($path);
            $base = realpath($manager()->pluginsDir() . '/' . $name . '/assets');
            if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
                return new Response(404);
            }
            $type = match (true) {
                str_ends_with($file, '.css') => 'text/css',
                str_ends_with($file, '.js') => 'text/javascript',
                str_ends_with($file, '.svg') => 'image/svg+xml',
                str_ends_with($file, '.woff2') => 'font/woff2',
                default => 'image/png',
            };
            return new Response(200, [
                'Content-Type' => $type . '; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
            ], (string) file_get_contents($real));
        });

        // ---- 管理 API(全部 system:manage)----
        $router->get('/api/v1/plugins', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            return Json::ok(200, ['items' => $manager()->list()]);
        });

        $router->post('/api/v1/plugins/{name}:install', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $manager()->install($name);
            return Json::ok(201, ['plugin' => self::row($manager(), $name)]);
        });

        $router->post('/api/v1/plugins/{name}:activate', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $manager()->activate($name);
            return Json::ok(200, ['plugin' => self::row($manager(), $name)]);
        });

        $router->post('/api/v1/plugins/{name}:deactivate', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $manager()->deactivate($name);
            return Json::ok(200, ['plugin' => self::row($manager(), $name)]);
        });

        $router->delete('/api/v1/plugins/{name}', static function (ServerRequestInterface $req) use ($manager): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = (string) (Body::routeParams($req)['name'] ?? '');
            $manager()->uninstall($name);
            return Json::ok(200, ['ok' => true]);
        });

        // ---- 后台插件页(04 §8)----
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

        $router->get('/admin/plugins', static function (ServerRequestInterface $req) use ($kernel, $manager, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            return self::adminPage($kernel, $req, $manager(), Body::of($req));
        });

        $router->post('/admin/plugins/{name}/{action}', static function (ServerRequestInterface $req) use ($kernel, $manager, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            $params = Body::routeParams($req);
            $name = (string) ($params['name'] ?? '');
            $action = (string) ($params['action'] ?? '');
            if (!in_array($action, ['install', 'activate', 'deactivate', 'uninstall'], true)) {
                return new Response(302, ['Location' => '/admin/plugins']);
            }
            try {
                $manager()->{$action}($name);
                return new Response(302, ['Location' => '/admin/plugins?ok=' . rawurlencode("{$name}:{$action}")]);
            } catch (ApiException $e) {
                return new Response(302, ['Location' => '/admin/plugins?error=' . rawurlencode($e->errorCode)]);
            }
        });

        $router->post('/admin/plugins/{name}/settings/{section}', static function (ServerRequestInterface $req) use ($kernel, $manager, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            $params = Body::routeParams($req);
            try {
                $manager()->saveSettings(
                    (string) ($params['name'] ?? ''),
                    (string) ($params['section'] ?? ''),
                    Body::of($req),
                );
                return new Response(302, ['Location' => '/admin/plugins?ok=settings']);
            } catch (ApiException $e) {
                return new Response(302, ['Location' => '/admin/plugins?error=' . rawurlencode($e->errorCode)]);
            }
        });
    }

    /** @param array<string, mixed> $query */
    private static function adminPage(Kernel $kernel, ServerRequestInterface $req, PluginManager $manager, array $query): ResponseInterface
    {
        $sections = [];
        foreach ($manager->settingSections() as $reg) {
            $sections[] = $reg + ['values' => $manager->settingValues($reg['plugin'], $reg['section'])];
        }
        return ThemeRoutes::renderAdmin($kernel, $req, 'admin_plugins', [
            'plugins' => $manager->list(),
            'errors' => $manager->discoveryErrors(),
            'sections' => $sections,
            'notice' => isset($query['ok']) ? '操作成功:' . (string) $query['ok'] : null,
            'failure' => isset($query['error']) ? '操作失败:' . (string) $query['error'] : null,
        ], 'plugins', '插件 · 后台');
    }

    /** @return array<string, mixed> */
    private static function row(PluginManager $manager, string $name): array
    {
        foreach ($manager->list() as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }
        throw ApiException::notFound('Plugin');
    }
}
