<?php

declare(strict_types=1);

namespace PenBlog\Api;

use PenBlog\Kernel\Kernel;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * OpenAPI 3.0 运行时导出(06 §7):注册表驱动 —— Router 路由快照 + 元数据表
 * (summary / x-permission / 公开性),x-* 扩展标注权限串。
 */
final class OpenApiRoutes
{
    /** "METHOD pattern" => ['summary'=>string, 'permission'=>?string, 'public'=>bool] */
    private const META = [
        // 健康与文档
        'GET /api/v1/health' => ['summary' => '引擎健康检查', 'public' => true],
        'GET /api/v1/openapi.json' => ['summary' => 'OpenAPI 文档导出', 'public' => true],
        // 认证(06 §2 / 09 §4)
        'POST /api/v1/auth/login' => ['summary' => '登录(建立会话)', 'public' => true],
        'POST /api/v1/auth/logout' => ['summary' => '登出'],
        'GET /api/v1/auth/csrf' => ['summary' => '获取 CSRF Token', 'public' => true],
        'POST /api/v1/auth/register' => ['summary' => '注册(开放时)', 'public' => true],
        'GET /api/v1/user/me' => ['summary' => '本人资料'],
        'PUT /api/v1/user/me' => ['summary' => '更新本人资料'],
        'GET /api/v1/access-levels' => ['summary' => '等级列表', 'public' => true],
        'PUT /api/v1/users/{name}/level' => ['summary' => '管理员调级', 'permission' => 'system:manage'],
        // PAT(06 §2)
        'POST /api/v1/tokens' => ['summary' => '签发 PAT(明文仅此一次返回)'],
        'GET /api/v1/tokens' => ['summary' => 'PAT 列表'],
        'DELETE /api/v1/tokens/{id}' => ['summary' => '吊销 PAT'],
        // 内容(06 §3 自动 CRUD)
        'GET /api/v1/posts' => ['summary' => '文章列表', 'public' => true],
        'POST /api/v1/posts' => ['summary' => '创建文章', 'permission' => 'post:write'],
        'GET /api/v1/posts/{slug}' => ['summary' => '获取文章', 'public' => true],
        'PUT /api/v1/posts/{slug}' => ['summary' => '更新文章', 'permission' => 'post:write'],
        'DELETE /api/v1/posts/{slug}' => ['summary' => '删除文章', 'permission' => 'post:write'],
        'POST /api/v1/posts/{slug}:publish' => ['summary' => '发布文章', 'permission' => 'post:write'],
        'GET /api/v1/posts/{slug}/comments' => ['summary' => '文章评论列表', 'public' => true],
        'POST /api/v1/posts/{slug}/comments' => ['summary' => '发表评论', 'permission' => 'comment:create'],
        'GET /api/v1/pages' => ['summary' => '页面列表', 'public' => true],
        'POST /api/v1/pages' => ['summary' => '创建页面', 'permission' => 'page:manage'],
        'GET /api/v1/pages/{slug}' => ['summary' => '获取页面', 'public' => true],
        'PUT /api/v1/pages/{slug}' => ['summary' => '更新页面', 'permission' => 'page:manage'],
        'DELETE /api/v1/pages/{slug}' => ['summary' => '删除页面', 'permission' => 'page:manage'],
        // 分类与标签(06 §3)
        'GET /api/v1/categories' => ['summary' => '分类列表', 'public' => true],
        'POST /api/v1/categories' => ['summary' => '创建分类', 'permission' => 'post:manage'],
        'PUT /api/v1/categories/{name}' => ['summary' => '重命名分类', 'permission' => 'post:manage'],
        'DELETE /api/v1/categories/{name}' => ['summary' => '删除分类', 'permission' => 'post:manage'],
        'GET /api/v1/tags' => ['summary' => '标签列表', 'public' => true],
        'POST /api/v1/tags' => ['summary' => '创建标签', 'permission' => 'post:manage'],
        'PUT /api/v1/tags/{name}' => ['summary' => '重命名标签', 'permission' => 'post:manage'],
        'DELETE /api/v1/tags/{name}' => ['summary' => '删除标签', 'permission' => 'post:manage'],
        // 通知(05 §9)
        'GET /api/v1/notifications' => ['summary' => '站内信列表'],
        'POST /api/v1/notifications/read-all' => ['summary' => '全部标记已读'],
        'GET /api/v1/notifications/unread-count' => ['summary' => '未读计数'],
        'GET /api/v1/notifications/preferences' => ['summary' => '通知偏好'],
        'PUT /api/v1/notifications/preferences' => ['summary' => '保存通知偏好'],
        'GET /api/v1/notifications/traces/{eventId}' => ['summary' => '投递轨迹', 'permission' => 'system:manage'],
        'POST /api/v1/notifications/{id}:read' => ['summary' => '标记已读'],
        'POST /api/v1/notifications/{id}:archive' => ['summary' => '归档'],
        // Webhook 出站(06 §8)
        'GET /api/v1/webhooks' => ['summary' => 'Webhook 列表', 'permission' => 'system:manage'],
        'POST /api/v1/webhooks' => ['summary' => '注册 Webhook(secret 明文仅此一次)', 'permission' => 'system:manage'],
        'GET /api/v1/webhooks/{id}/deliveries' => ['summary' => '投递记录', 'permission' => 'system:manage'],
        'DELETE /api/v1/webhooks/{id}' => ['summary' => '删除 Webhook', 'permission' => 'system:manage'],
        'POST /api/v1/webhooks/{id}:test' => ['summary' => '发送测试事件', 'permission' => 'system:manage'],
        // 插件系统(04 §8)
        'GET /api/v1/plugins' => ['summary' => '插件列表(含未安装)', 'permission' => 'system:manage'],
        'POST /api/v1/plugins/{name}:install' => ['summary' => '安装插件(校验 manifest 与 requires)', 'permission' => 'system:manage'],
        'POST /api/v1/plugins/{name}:activate' => ['summary' => '激活插件(依赖拓扑 + onActivate)', 'permission' => 'system:manage'],
        'POST /api/v1/plugins/{name}:deactivate' => ['summary' => '停用插件(撤销其全部注册)', 'permission' => 'system:manage'],
        'DELETE /api/v1/plugins/{name}' => ['summary' => '卸载插件(清理 plugin.{name}.* 数据)', 'permission' => 'system:manage'],
        // 主题系统(03 §7)
        'GET /api/v1/themes' => ['summary' => '主题列表(含激活状态与发现错误)', 'permission' => 'system:manage'],
        'POST /api/v1/themes/{name}:activate' => ['summary' => '激活主题(校验继承链完整)', 'permission' => 'system:manage'],
        'POST /api/v1/themes/{name}:deactivate' => ['summary' => '停用主题(当前激活主题需先切换)', 'permission' => 'system:manage'],
        'DELETE /api/v1/themes/{name}' => ['summary' => '卸载主题(清理 theme.{name}.* 设置,文件保留)', 'permission' => 'system:manage'],
        'GET /api/v1/themes/{name}/settings' => ['summary' => '主题设置(声明 schema + 当前值)', 'permission' => 'system:manage'],
        'PUT /api/v1/themes/{name}/settings' => ['summary' => '保存主题设置(按声明类型校验/钳制)', 'permission' => 'system:manage'],
        // 菜单系统(03 §7)
        'GET /api/v1/menus' => ['summary' => '菜单列表(全部位置)', 'permission' => 'system:manage'],
        'GET /api/v1/menus/{location}' => ['summary' => '单个菜单(条目 + resourceVersion)', 'permission' => 'system:manage'],
        'PUT /api/v1/menus/{location}' => ['summary' => '保存菜单(校验后整表覆盖)', 'permission' => 'system:manage'],
        'DELETE /api/v1/menus/{location}' => ['summary' => '删除菜单(前台回退默认导航)', 'permission' => 'system:manage'],
    ];

    /** 集合 GET 端点共用的列表查询参数(06 §4) */
    private const COLLECTIONS = [
        '/api/v1/posts', '/api/v1/pages', '/api/v1/notifications',
        '/api/v1/webhooks', '/api/v1/tokens', '/api/v1/access-levels',
    ];

    public static function register(Kernel $kernel): void
    {
        $kernel->router->get('/api/v1/openapi.json', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            unset($req);
            return Json::ok(200, self::spec($kernel));
        });
    }

    /** @return array<string, mixed> */
    public static function spec(Kernel $kernel): array
    {
        $paths = [];
        foreach ($kernel->router->routes() as $route) {
            if (!str_starts_with($route['pattern'], '/api/v1/')) {
                continue;
            }
            // 插件动态端点元数据(04 §4 APIEndpoint):激活后经 Context 记账并入文档
            $meta = self::META[$route['method'] . ' ' . $route['pattern']]
                ?? $kernel->plugins()->apiMeta()[$route['method'] . ' ' . $route['pattern']]
                ?? null;
            $operationId = self::operationId($route['method'], $route['pattern']);
            $operation = [
                'operationId' => $operationId,
                'tags' => self::tag($route['pattern']),
                'summary' => $meta['summary'] ?? $operationId,
                'responses' => self::responses($route['method']),
            ];
            if ($meta === null) {
                $operation['x-undocumented'] = true;
            }
            if (($meta['plugin'] ?? null) !== null) {
                $operation['x-plugin'] = $meta['plugin'];
            }
            if (($meta['permission'] ?? null) !== null) {
                $operation['x-permission'] = $meta['permission'];
            }
            $operation['security'] = ($meta['public'] ?? false)
                ? []
                : [['sessionCookie' => []], ['patBearer' => []]];
            if (!($meta['public'] ?? false) && $route['method'] !== 'GET') {
                $operation['x-csrf'] = 'Cookie 会话写操作需 X-CSRF-Token 头(或 _csrf 字段);Bearer PAT 免 CSRF';
            }

            $params = [];
            preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $route['pattern'], $m);
            foreach ($m[1] as $name) {
                $params[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
            }
            if ($route['method'] === 'GET' && in_array($route['pattern'], self::COLLECTIONS, true)) {
                $params[] = ['$ref' => '#/components/parameters/Page'];
                $params[] = ['$ref' => '#/components/parameters/Size'];
                $params[] = ['$ref' => '#/components/parameters/Cursor'];
                $params[] = ['$ref' => '#/components/parameters/Sort'];
                $params[] = ['$ref' => '#/components/parameters/Filter'];
            }
            if ($params !== []) {
                $operation['parameters'] = $params;
            }
            if ($route['method'] === 'POST' || $route['method'] === 'PUT') {
                $operation['requestBody'] = [
                    'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                ];
            }

            $opKey = strtolower($route['method']);
            $paths[$route['pattern']][$opKey] = $operation;
        }
        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'PenBlog API',
                'version' => Kernel::VERSION,
                'description' => 'API First(06):资源复数 kebab-case、自定义方法冒号、统一错误格式、游标分页。',
            ],
            'servers' => [['url' => '/']],
            'components' => [
                'securitySchemes' => [
                    'sessionCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'penblog_session'],
                    'patBearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => '个人访问令牌 pb_ 前缀,可 scopes 限定'],
                ],
                'parameters' => [
                    'Page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    'Size' => ['name' => 'size', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 100]],
                    'Cursor' => ['name' => 'cursor', 'in' => 'query', 'description' => '不透明 base64 游标', 'schema' => ['type' => 'string']],
                    'Sort' => ['name' => 'sort', 'in' => 'query', 'description' => '多列排序,逗号分隔,"-" 前缀降序', 'schema' => ['type' => 'string']],
                    'Filter' => ['name' => 'filter[field]', 'in' => 'query', 'description' => '字段白名单等值过滤,支持 [gte]/[lte]/[in]', 'schema' => ['type' => 'string']],
                ],
            ],
            'paths' => $paths,
        ];
    }

    /** @return array<string, mixed> */
    private static function responses(string $method): array
    {
        $ok = ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];
        $out = ['default' => $ok];
        if ($method === 'POST') {
            $out['201'] = $ok;
        }
        return $out;
    }

    private static function operationId(string $method, string $pattern): string
    {
        $clean = preg_replace('#^/api/v1/#', '', $pattern) ?? $pattern;
        $parts = preg_split('#[/{}:]+#', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $id = strtolower($method) . array_reduce(
            $parts,
            static fn (string $carry, string $part): string => $carry . ucfirst(preg_replace('#[^a-zA-Z0-9_]#', '', $part) ?? $part),
            '',
        );
        return $id;
    }

    /** @return list<string> */
    private static function tag(string $pattern): array
    {
        $seg = explode('/', ltrim($pattern, '/'))[2] ?? 'misc';
        return [match (true) {
            $seg === 'auth' || $seg === 'user' || $seg === 'users' || $seg === 'tokens' || $seg === 'access-levels' => 'auth',
            $seg === 'notifications' => 'notifications',
            $seg === 'webhooks' => 'webhooks',
            $seg === 'health' || $seg === 'openapi.json' => 'meta',
            default => 'content',
        }];
    }
}
