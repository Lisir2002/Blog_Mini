<?php

declare(strict_types=1);

namespace PenBlog\Menu;

use Nyholm\Psr7\Response;
use PenBlog\Api\ApiException;
use PenBlog\Api\Body;
use PenBlog\Api\Json;
use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Extension;
use PenBlog\Kernel\Kernel;
use PenBlog\Theme\ThemeRoutes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 菜单管理面(03 §7):/api/v1/menus(system:manage)+ 后台 /admin/menus 页。
 */
final class MenuAdminRoutes
{
    public static function register(Kernel $kernel): void
    {
        $router = $kernel->router;
        $menus = static fn (): MenuManager => $kernel->menus();

        // ---- 管理 API(全部 system:manage)----
        $router->get('/api/v1/menus', static function (ServerRequestInterface $req) use ($menus): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            return Json::ok(200, ['items' => $menus()->all()]);
        });

        $router->get('/api/v1/menus/{location}', static function (ServerRequestInterface $req) use ($menus): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            return Json::ok(200, ['menu' => self::row(self::mustGet($menus(), (string) (Body::routeParams($req)['location'] ?? '')))]);
        });

        $router->put('/api/v1/menus/{location}', static function (ServerRequestInterface $req) use ($menus): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $location = (string) (Body::routeParams($req)['location'] ?? '');
            $menu = $menus()->save($location, array_values((array) (Body::of($req)['items'] ?? [])));
            return Json::ok(200, ['menu' => self::row($menu)]);
        });

        $router->delete('/api/v1/menus/{location}', static function (ServerRequestInterface $req) use ($menus): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $location = (string) (Body::routeParams($req)['location'] ?? '');
            if (!$menus()->delete($location)) {
                throw ApiException::notFound('Menu');
            }
            return Json::ok(200, ['ok' => true]);
        });

        // ---- 后台菜单页 ----
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

        $router->get('/admin/menus', static function (ServerRequestInterface $req) use ($kernel, $menus, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            return self::adminPage($kernel, $req, $menus(), $req->getQueryParams());
        });

        // 保存:{location} 来自路由;新建走 POST /admin/menus,location 来自表单
        $saveHandler = static function (ServerRequestInterface $req, string $location) use ($menus): ResponseInterface {
            $rows = [];
            foreach ((array) (Body::of($req)['items'] ?? []) as $row) {
                $row = (array) $row;
                if (trim((string) ($row['label'] ?? '')) === '') {
                    continue; // 空行忽略
                }
                $rows[] = $row;
            }
            try {
                $menus()->save($location, $rows);
                return new Response(302, ['Location' => '/admin/menus?ok=' . rawurlencode($location)]);
            } catch (ApiException $e) {
                return new Response(302, ['Location' => '/admin/menus?error=' . rawurlencode($e->errorCode)]);
            }
        };

        $router->post('/admin/menus', static function (ServerRequestInterface $req) use ($manageGate, $saveHandler): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            return $saveHandler($req, trim((string) (Body::of($req)['location'] ?? '')));
        });

        $router->post('/admin/menus/{location}', static function (ServerRequestInterface $req) use ($manageGate, $saveHandler): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            return $saveHandler($req, (string) (Body::routeParams($req)['location'] ?? ''));
        });

        $router->post('/admin/menus/{location}/delete', static function (ServerRequestInterface $req) use ($menus, $manageGate): ResponseInterface {
            if (($r = $manageGate($req)) !== null) {
                return $r;
            }
            $location = (string) (Body::routeParams($req)['location'] ?? '');
            $menus()->delete($location);
            return new Response(302, ['Location' => '/admin/menus?deleted=' . rawurlencode($location)]);
        });
    }

    /** @param array<string, mixed> $query */
    private static function adminPage(Kernel $kernel, ServerRequestInterface $req, MenuManager $menus, array $query): ResponseInterface
    {
        $edit = isset($query['edit']) ? (string) $query['edit'] : null;
        $current = $edit !== null ? $menus->get($edit) : null;
        $items = $current !== null ? array_values((array) ($current->spec['items'] ?? [])) : [];
        // 固定 8 行编辑网格,不足补空行
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $row = (array) ($items[$i] ?? []);
            $rows[] = [
                'label' => (string) ($row['label'] ?? ''),
                'type' => (string) ($row['type'] ?? 'page'),
                'target' => (string) ($row['target'] ?? ''),
                'sort' => (int) ($row['sort'] ?? 0),
            ];
        }
        return ThemeRoutes::renderAdmin($kernel, $req, 'admin_menus', [
            'menus' => $menus->all(),
            'edit' => $edit,
            'editing' => $current !== null,
            'rows' => $rows,
            'types' => MenuManager::TYPES,
            'typeLabels' => ['post' => '文章', 'page' => '页面', 'category' => '分类', 'author' => '作者', 'url' => '外部链接'],
            'notice' => isset($query['ok']) ? "菜单 {$query['ok']} 已保存" : (isset($query['deleted']) ? "菜单 {$query['deleted']} 已删除" : null),
            'failure' => isset($query['error']) ? '保存失败:' . (string) $query['error'] : null,
        ], 'menus', '菜单 · 后台');
    }

    /** @return array{location:string, items:list<array<string, mixed>>, resourceVersion:int} */
    private static function row(Extension $menu): array
    {
        return [
            'location' => $menu->metadata->name,
            'items' => array_values((array) ($menu->spec['items'] ?? [])),
            'resourceVersion' => $menu->metadata->resourceVersion,
        ];
    }

    private static function mustGet(MenuManager $menus, string $location): Extension
    {
        $menu = $menus->get($location);
        if ($menu === null) {
            throw ApiException::notFound('Menu');
        }
        return $menu;
    }
}
