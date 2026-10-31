<?php

declare(strict_types=1);

namespace PenBlog\Theme;

use Nyholm\Psr7\Response;
use PenBlog\Api\ApiException;
use PenBlog\Api\Body;
use PenBlog\Auth\Gate;
use PenBlog\Auth\PasswordHasher;
use PenBlog\Auth\SessionStore;
use PenBlog\Auth\UserRepository;
use PenBlog\Content\Page as PageDoc;
use PenBlog\Content\Post;
use PenBlog\Content\PostRepository;
use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Kernel;
use PenBlog\Kernel\ListQuery;
use PenBlog\Repository\SqlDriver;
use PenBlog\Support\Markdown;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Paper 主题路由(08 §4/§10):前台页面 + 后台骨架 + 静态资源。
 * 所有动态输出经 View::esc;Markdown 经安全渲染器(先转义后结构替换)。
 */
final class ThemeRoutes
{
    public static function register(Kernel $kernel): void
    {
        $c = $kernel->container;
        $router = $kernel->router;
        $posts = static fn (): PostRepository => $c->get(PostRepository::class);
        $users = static fn (): UserRepository => $c->get(UserRepository::class);

        // ---- 静态资源(03 §6:沿继承链解析 assets/{file},basename 白名单)----
        $router->get('/assets/{file}', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            $file = (string) (Body::routeParams($req)['file'] ?? '');
            if (!preg_match('/^[a-z0-9_-]+\.(css|js|svg|png|jpg|woff2)$/', $file)) {
                return new Response(404);
            }
            $path = $kernel->theme()->assetPath($kernel->theme()->active(), $file);
            if ($path === null) {
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
            ], (string) file_get_contents($path));
        });

        // ---- 首页 ----
        $router->get('/', static function (ServerRequestInterface $req) use ($kernel, $posts): ResponseInterface {
            $params = Body::listQuery($req->getQueryParams());
            // 主题 Setting post_per_page(03 §5):未显式指定 size 时生效
            $perPage = (int) $kernel->theme()->activeOption('post_per_page', 0);
            if ($perPage >= 3 && $perPage <= 50 && !isset($params['size'])) {
                $params['size'] = $perPage;
            }
            $result = $posts()->query(
                ListQuery::fromParams($params),
                Gate::anonymous(),
            );
            /** @var list<Post> $items */
            $items = $result->items;
            return self::page($kernel, $req, 'home', [
                'posts' => $items,
                'authors' => self::authorNames($kernel, array_map(static fn (Post $p): string => $p->author(), $items)),
                'page' => $result->page,
                'total' => $result->total,
                'nextCursor' => $result->nextCursor,
                'siteTagline' => (string) $kernel->options()->get('site.description', '基于 PenBlog 构建的个人博客'),
            ], 'home');
        });

        // ---- 文章页 ----
        $router->get('/posts/{slug}', static function (ServerRequestInterface $req) use ($kernel, $posts, $users, $c): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $post = $posts()->findPublished($slug);
            if ($post === null) {
                return self::notFound($kernel, $req);
            }
            $gate = AuthMiddleware::gate($req);
            $pdo = $c->get(SqlDriver::class)->pdo();
            $stmt = $pdo->prepare(
                "SELECT cm.*, u.username, u.display_name FROM comments cm
                 LEFT JOIN users u ON u.id = cm.user_id
                 WHERE cm.post_id = :p AND cm.status = 'approved' ORDER BY cm.created_at ASC LIMIT 200"
            );
            $stmt->execute(['p' => self::postId($pdo, $post->slug())]);
            $comments = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'body' => (string) $r['body'],
                'status' => (string) $r['status'],
                'createdAt' => (string) $r['created_at'],
                'displayName' => $r['username'] !== null ? (string) $r['display_name'] : (string) ($r['author_name'] ?? '匿名'),
                'username' => $r['username'] !== null ? (string) $r['username'] : null,
            ], $stmt->fetchAll());

            return self::page($kernel, $req, 'post', [
                'post' => $post,
                'author' => $users()->findByUsername($post->author()),
                'bodyHtml' => Markdown::toHtml($post->bodyMd()),
                'comments' => $comments,
                'canComment' => $gate->isAuthenticated()
                    && ($gate->allows('comment:create') || $gate->allows('comment:moderate')),
            ], null, $post->title());
        });

        // ---- 评论表单端点(不在 CsrfMiddleware 前缀内,手动双提交校验)----
        $router->post('/posts/{slug}/comments', static function (ServerRequestInterface $req) use ($kernel, $posts, $c): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $back = '/posts/' . rawurlencode($slug);
            $post = $posts()->findPublished($slug);
            if ($post === null) {
                return self::notFound($kernel, $req);
            }
            $gate = AuthMiddleware::gate($req);
            if (!$gate->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/login']);
            }
            if (!$gate->allows('comment:create') && !$gate->allows('comment:moderate')) {
                return new Response(302, ['Location' => $back . '?error=forbidden']);
            }
            $cookie = (string) ($req->getCookieParams()[SessionStore::CSRF_COOKIE] ?? '');
            $sent = (string) (Body::of($req)['_csrf'] ?? '');
            if ($cookie === '' || $sent === '' || !hash_equals($cookie, $sent)) {
                return new Response(302, ['Location' => $back . '?error=csrf']);
            }
            $text = trim((string) (Body::of($req)['body'] ?? ''));
            if ($text === '' || mb_strlen($text) > 2000) {
                return new Response(302, ['Location' => $back . '?error=invalid']);
            }
            $driver = $c->get(SqlDriver::class);
            $status = $gate->allows('comment:moderate') ? 'approved' : 'pending';
            $commentId = $driver->insertReturningId('comments', [
                'post_id' => self::postId($driver->pdo(), $post->slug()),
                'user_id' => $gate->user->id(),
                'author_name' => null,
                'author_email' => null,
                'parent_id' => null,
                'body' => $text,
                'status' => $status,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $c->get(\PenBlog\Kernel\EventBus::class)->dispatch(new \PenBlog\Notification\Events\CommentCreated(
                commentId: $commentId,
                postSlug: $post->slug(),
                postTitle: $post->title(),
                postAuthorUsername: $post->author(),
                authorName: $gate->user->displayName(),
                userId: $gate->user->id(),
                status: $status,
                parentId: null,
                excerpt: mb_substr($text, 0, 80),
            ));
            return new Response(302, ['Location' => $back . '#comments']);
        });

        // ---- 作者页 ----
        $router->get('/authors/{username}', static function (ServerRequestInterface $req) use ($kernel, $posts, $users): ResponseInterface {
            $username = (string) (Body::routeParams($req)['username'] ?? '');
            $user = $users()->findByUsername($username);
            if ($user === null) {
                return self::notFound($kernel, $req);
            }
            $result = $posts()->query(
                ListQuery::fromParams(['size' => 50, 'filter' => ['author' => $username]], ['published_at'], ['author']),
                Gate::anonymous(),
            );
            return self::page($kernel, $req, 'author', [
                'user' => $user,
                'posts' => $result->items,
            ], null, $user->displayName());
        });

        // ---- 独立页面 ----
        $router->get('/p/{slug}', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $found = $kernel->extensions()->find('Page', $slug);
            if (!$found instanceof PageDoc
                || (!$found->isPublished() && !AuthMiddleware::gate($req)->allows('page:manage'))) {
                return self::notFound($kernel, $req);
            }
            return self::page($kernel, $req, 'page', [
                'page' => $found,
                'bodyHtml' => Markdown::toHtml($found->bodyMd()),
            ], null, $found->title());
        });

        // ---- 后台 ----
        $router->get('/admin', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            return new Response(302, ['Location' => AuthMiddleware::gate($req)->isAuthenticated()
                ? '/admin/dashboard' : '/admin/login']);
        });

        $router->get('/admin/login', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            if (AuthMiddleware::gate($req)->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/dashboard']);
            }
            return self::loginPage($kernel, $req, null);
        });

        $router->post('/admin/login', static function (ServerRequestInterface $req) use ($kernel, $c): ResponseInterface {
            if (AuthMiddleware::gate($req)->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/dashboard']);
            }
            $body = Body::of($req);
            $identifier = trim((string) ($body['username'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $remember = !empty($body['remember']);

            /** @var UserRepository $users */
            $users = $c->get(UserRepository::class);
            /** @var PasswordHasher $hasher */
            $hasher = $c->get(PasswordHasher::class);
            /** @var SessionStore $sessions */
            $sessions = $c->get(SessionStore::class);

            $user = $identifier !== '' && $password !== '' ? $users->findForLogin($identifier) : null;
            if ($user === null || $user->status() !== 'active' || !$hasher->verify($password, $users->passwordHashFor($user->id()))) {
                return self::loginPage($kernel, $req, '用户名或密码错误', 401);
            }
            $users->rehashIfNeeded($user, $password);
            // 会话固定防护:登录即轮换(与 AuthRoutes 一致)
            $sessions->revokeAllForUser($user->id());
            $ip = $req->getHeaderLine('X-Forwarded-For') ?: (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $raw = $sessions->create($user->id(), $ip !== '' ? $ip : null, $req->getHeaderLine('User-Agent') ?: null, $remember);
            $csrf = SessionStore::csrfToken();

            return new Response(302, ['Location' => '/admin/dashboard'] + ['Set-Cookie' => self::authCookies($raw, $csrf, $remember)]);
        });

        $router->post('/admin/logout', static function (ServerRequestInterface $req) use ($c): ResponseInterface {
            /** @var SessionStore $sessions */
            $sessions = $c->get(SessionStore::class);
            $raw = (string) ($req->getCookieParams()[SessionStore::COOKIE] ?? '');
            if ($raw !== '') {
                $sessions->revoke($raw);
            }
            return new Response(302, [
                'Location' => '/admin/login',
                'Set-Cookie' => [self::expiredCookie(SessionStore::COOKIE), self::expiredCookie(SessionStore::CSRF_COOKIE)],
            ]);
        });

        $router->get('/admin/dashboard', static function (ServerRequestInterface $req) use ($kernel, $posts): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            if (!$gate->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/login']);
            }
            $result = $posts()->query(ListQuery::fromParams(['size' => 50]), $gate);
            return self::page($kernel, $req, 'admin_dashboard', [
                'posts' => $result->items,
                'total' => $result->total,
            ], 'dashboard', '后台 · 内容', 'admin');
        });

        foreach (['publish', 'delete'] as $action) {
            $router->post("/admin/posts/{slug}/{$action}", static function (ServerRequestInterface $req) use ($kernel, $posts, $action): ResponseInterface {
                $slug = (string) (Body::routeParams($req)['slug'] ?? '');
                $gate = AuthMiddleware::gate($req);
                if (!$gate->isAuthenticated()) {
                    return new Response(302, ['Location' => '/admin/login']);
                }
                $author = $posts()->authorOf($slug);
                if ($author === null) {
                    return new Response(302, ['Location' => '/admin/dashboard']);
                }
                if (!$gate->canWritePost($author)) {
                    return new Response(302, ['Location' => '/admin/dashboard?error=forbidden']);
                }
                if ($action === 'publish') {
                    $posts()->save($slug, ['status' => 'published'], $gate);
                } else {
                    $posts()->delete($slug);
                }
                return new Response(302, ['Location' => '/admin/dashboard']);
            });
        }

        // ---- Markdown 编辑器(11 §3/§4)----
        // POST 均走 CsrfMiddleware(/admin/ 前缀 + _csrf 表单字段),此处不重复校验。
        $canWrite = static function (Gate $gate): bool {
            return $gate->isAuthenticated() && ($gate->allows('post:write') || $gate->allows('post:manage'));
        };

        $router->get('/admin/posts/new', static function (ServerRequestInterface $req) use ($kernel, $canWrite): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            if (!$gate->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/login']);
            }
            if (!$canWrite($gate)) {
                return new Response(302, ['Location' => '/admin/dashboard?error=forbidden']);
            }
            return self::editorPage($kernel, $req, [
                'post' => null,
                'values' => self::editorDefaults(),
                'resourceVersion' => null,
                'action' => '/admin/posts/new',
                'draftKey' => 'new',
                'heading' => '写文章',
                'error' => null,
                'conflict' => false,
                'saved' => isset($req->getQueryParams()['saved']),
            ]);
        });

        $router->post('/admin/posts/new', static function (ServerRequestInterface $req) use ($kernel, $posts, $canWrite): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            if (!$gate->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/login']);
            }
            if (!$canWrite($gate)) {
                return new Response(302, ['Location' => '/admin/dashboard?error=forbidden']);
            }
            $values = self::editorInput(Body::of($req));
            $error = self::editorValidate($values);
            if ($error === null) {
                try {
                    $post = $posts()->save(null, self::editorSave($values), $gate);
                    return new Response(302, ['Location' => '/admin/posts/' . rawurlencode($post->slug()) . '/edit?saved=1']);
                } catch (ApiException $e) {
                    $error = $e->getMessage();
                }
            }
            return self::editorPage($kernel, $req, [
                'post' => null, 'values' => $values, 'resourceVersion' => null,
                'action' => '/admin/posts/new', 'draftKey' => 'new',
                'heading' => '写文章', 'error' => $error, 'conflict' => false, 'saved' => false,
            ], 400);
        });

        $router->get('/admin/posts/{slug}/edit', static function (ServerRequestInterface $req) use ($kernel, $posts, $canWrite): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $gate = AuthMiddleware::gate($req);
            if (!$gate->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/login']);
            }
            if (!$canWrite($gate)) {
                return new Response(302, ['Location' => '/admin/dashboard?error=forbidden']);
            }
            try {
                $post = $posts()->get($slug, $gate);
            } catch (ApiException) {
                return self::notFound($kernel, $req);
            }
            if (!$gate->canWritePost($post->author())) {
                return new Response(302, ['Location' => '/admin/dashboard?error=forbidden']);
            }
            return self::editorPage($kernel, $req, [
                'post' => $post,
                'values' => self::editorFromPost($post),
                'resourceVersion' => $post->metadata->resourceVersion,
                'action' => '/admin/posts/' . rawurlencode($post->slug()) . '/edit',
                'draftKey' => $post->slug(),
                'heading' => '编辑文章',
                'error' => null,
                'conflict' => false,
                'saved' => isset($req->getQueryParams()['saved']),
            ]);
        });

        $router->post('/admin/posts/{slug}/edit', static function (ServerRequestInterface $req) use ($kernel, $posts, $canWrite): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $gate = AuthMiddleware::gate($req);
            if (!$gate->isAuthenticated()) {
                return new Response(302, ['Location' => '/admin/login']);
            }
            if (!$canWrite($gate)) {
                return new Response(302, ['Location' => '/admin/dashboard?error=forbidden']);
            }
            $author = $posts()->authorOf($slug);
            if ($author === null) {
                return new Response(302, ['Location' => '/admin/dashboard']);
            }
            if (!$gate->canWritePost($author)) {
                return new Response(302, ['Location' => '/admin/dashboard?error=forbidden']);
            }
            $body = Body::of($req);
            $values = self::editorInput($body);
            $save = self::editorSave($values);
            $rv = (string) ($body['resourceVersion'] ?? '');
            if ($rv !== '' && ctype_digit($rv)) {
                $save['metadata']['resourceVersion'] = (int) $rv;
            }
            $error = self::editorValidate($values);
            $conflict = false;
            if ($error === null) {
                try {
                    $post = $posts()->save($slug, $save, $gate);
                    return new Response(302, ['Location' => '/admin/posts/' . rawurlencode($post->slug()) . '/edit?saved=1']);
                } catch (ApiException $e) {
                    $error = $e->getMessage();
                    $conflict = $e->errorCode === 'resource_version_conflict';
                }
            }
            // 冲突/校验失败:回显用户输入,绝不静默丢弃(11 §3.4)
            return self::editorPage($kernel, $req, [
                'post' => null, 'values' => $values,
                'resourceVersion' => $rv !== '' && ctype_digit($rv) ? (int) $rv : null,
                'action' => '/admin/posts/' . rawurlencode($slug) . '/edit',
                'draftKey' => $slug, 'heading' => '编辑文章',
                'error' => $error, 'conflict' => $conflict, 'saved' => false,
            ], $conflict ? 409 : 400);
        });

        // 服务端权威预览(11 §3.3):防抖调用,返回已净化 HTML 片段。
        $router->post('/admin/preview', static function (ServerRequestInterface $req) use ($canWrite): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            if (!$gate->isAuthenticated()) {
                return new Response(401, ['Content-Type' => 'text/plain; charset=utf-8'], '需要登录');
            }
            if (!$canWrite($gate)) {
                return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], '无权限');
            }
            $md = (string) (Body::of($req)['bodyMd'] ?? '');
            if (strlen($md) > Markdown::MAX_INPUT) {
                $md = substr($md, 0, Markdown::MAX_INPUT);
            }
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], Markdown::toHtml($md));
        });

        // ---- 通知中心(05 §7 / 08 §4.4)----
        $notifs = static fn (): \PenBlog\Notification\NotificationRepository => $c->get(\PenBlog\Notification\NotificationRepository::class);
        $requireAdmin = static function (ServerRequestInterface $req): ?ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            return $gate->isAuthenticated() ? null : new Response(302, ['Location' => '/admin/login']);
        };

        $router->get('/admin/notifications', static function (ServerRequestInterface $req) use ($kernel, $notifs, $requireAdmin): ResponseInterface {
            if (($r = $requireAdmin($req)) !== null) {
                return $r;
            }
            $gate = AuthMiddleware::gate($req);
            $query = $req->getQueryParams();
            $category = (string) ($query['category'] ?? '');
            if ($category !== '' && !\PenBlog\Notification\NotificationPolicy::isKnown($category)) {
                $category = '';
            }
            $page = max(1, (int) ($query['page'] ?? 1));
            $size = 30;
            $result = $notifs()->list($gate->user->id(), ['category' => $category], $page, $size);
            return self::page($kernel, $req, 'admin_notifications', [
                'items' => $result['items'],
                'total' => $result['total'],
                'page' => $page,
                'size' => $size,
                'category' => $category,
                'unread' => $notifs()->unreadCount($gate->user->id()),
                'categories' => \PenBlog\Notification\NotificationPolicy::all(),
                'saved' => isset($query['saved']),
            ], 'notifications', '通知中心 · 后台', 'admin');
        });

        $router->get('/admin/notifications/preferences', static function (ServerRequestInterface $req) use ($kernel, $c, $requireAdmin): ResponseInterface {
            if (($r = $requireAdmin($req)) !== null) {
                return $r;
            }
            $gate = AuthMiddleware::gate($req);
            $prefsRepo = $c->get(\PenBlog\Notification\PreferenceRepository::class);
            $cards = [];
            foreach (\PenBlog\Notification\NotificationPolicy::all() as $category => $meta) {
                $resolved = $prefsRepo->resolve($gate->user->id(), $category);
                $row = $prefsRepo->allFor($gate->user->id())[$category] ?? null;
                $cards[] = [
                    'category' => $category,
                    'label' => $meta['label'],
                    'defaultChannels' => $meta['channels'],
                    'useDefault' => $row === null || (int) $row['use_default'] === 1,
                    'channels' => array_map('strval', (array) json_decode((string) ($row['channels_json'] ?? '[]'), true)),
                    'quietStart' => (string) ($row['quiet_start'] ?? ''),
                    'quietEnd' => (string) ($row['quiet_end'] ?? ''),
                    'quietTz' => (string) ($row['quiet_tz'] ?? ''),
                ];
            }
            return self::page($kernel, $req, 'admin_preferences', [
                'cards' => $cards,
                'channels' => \PenBlog\Notification\PreferenceRepository::CHANNELS,
                'webhookUrl' => (string) $c->get(\PenBlog\Kernel\OptionsStore::class)->get('notify.webhook.url.' . $gate->user->id(), ''),
                'saved' => isset($req->getQueryParams()['saved']),
            ], 'notifications', '通知偏好 · 后台', 'admin');
        });

        $router->post('/admin/notifications/read-all', static function (ServerRequestInterface $req) use ($notifs, $requireAdmin): ResponseInterface {
            if (($r = $requireAdmin($req)) !== null) {
                return $r;
            }
            $notifs()->markAllRead(AuthMiddleware::gate($req)->user->id());
            return new Response(302, ['Location' => '/admin/notifications']);
        });

        $router->post('/admin/notifications/test', static function (ServerRequestInterface $req) use ($c, $requireAdmin): ResponseInterface {
            if (($r = $requireAdmin($req)) !== null) {
                return $r;
            }
            $gate = AuthMiddleware::gate($req);
            $c->get(\PenBlog\Notification\NotificationPipeline::class)->process(new \PenBlog\Notification\NotifyRequest(
                eventId: \PenBlog\Notification\NotifyRequest::uuid(),
                category: 'system',
                recipient: $gate->user->id(),
                templateKey: 'system.test',
                payload: [],
                link: '/admin/notifications',
            ));
            return new Response(302, ['Location' => '/admin/notifications?saved=test']);
        });

        foreach (['read', 'archive'] as $verb) {
            $router->post("/admin/notifications/{id}/{$verb}", static function (ServerRequestInterface $req) use ($notifs, $requireAdmin, $verb): ResponseInterface {
                if (($r = $requireAdmin($req)) !== null) {
                    return $r;
                }
                $id = (int) (Body::routeParams($req)['id'] ?? 0);
                $uid = AuthMiddleware::gate($req)->user->id();
                $verb === 'read' ? $notifs()->markRead($id, $uid) : $notifs()->archive($id, $uid);
                return new Response(302, ['Location' => '/admin/notifications']);
            });
        }

        $router->post('/admin/notifications/preferences', static function (ServerRequestInterface $req) use ($c, $requireAdmin): ResponseInterface {
            if (($r = $requireAdmin($req)) !== null) {
                return $r;
            }
            $gate = AuthMiddleware::gate($req);
            $body = Body::of($req);
            $category = (string) ($body['category'] ?? '');
            if (!\PenBlog\Notification\NotificationPolicy::isKnown($category)) {
                return new Response(302, ['Location' => '/admin/notifications/preferences']);
            }
            $c->get(\PenBlog\Notification\PreferenceRepository::class)->save($gate->user->id(), $category, [
                'use_default' => !empty($body['use_default']),
                'channels' => (array) ($body['channels'] ?? []),
                'quiet_start' => $body['quiet_start'] ?? null,
                'quiet_end' => $body['quiet_end'] ?? null,
                'quiet_tz' => $body['quiet_tz'] ?? null,
            ]);
            return new Response(302, ['Location' => '/admin/notifications/preferences?saved=1']);
        });

        $router->post('/admin/notifications/webhook', static function (ServerRequestInterface $req) use ($c, $requireAdmin): ResponseInterface {
            if (($r = $requireAdmin($req)) !== null) {
                return $r;
            }
            $gate = AuthMiddleware::gate($req);
            $options = $c->get(\PenBlog\Kernel\OptionsStore::class);
            $url = trim((string) (Body::of($req)['webhook_url'] ?? ''));
            $secret = trim((string) (Body::of($req)['webhook_secret'] ?? ''));
            if ($url === '') {
                $options->delete('notify.webhook.url.' . $gate->user->id());
            } elseif (preg_match('#^https?://#i', $url)) {
                $options->set('notify.webhook.url.' . $gate->user->id(), $url);
            }
            if ($secret !== '') {
                $options->set('notify.webhook.secret.' . $gate->user->id(), $secret);
            }
            return new Response(302, ['Location' => '/admin/notifications/preferences?saved=1']);
        });
    }

    /** @return array<string, string> */
    private static function editorDefaults(): array
    {
        return ['title' => '', 'slug' => '', 'bodyMd' => '', 'excerpt' => '', 'status' => 'draft', 'categories' => '', 'tags' => ''];
    }

    /** @return array<string, string> 表单回显值(categories/tags 保持逗号串) */
    private static function editorInput(array $body): array
    {
        return [
            'title' => trim((string) ($body['title'] ?? '')),
            'slug' => trim((string) ($body['slug'] ?? '')),
            'bodyMd' => (string) ($body['bodyMd'] ?? ''),
            'excerpt' => trim((string) ($body['excerpt'] ?? '')),
            'status' => (string) ($body['status'] ?? 'draft'),
            'categories' => trim((string) ($body['categories'] ?? '')),
            'tags' => trim((string) ($body['tags'] ?? '')),
        ];
    }

    /** @param array<string, string> $values @return array<string, mixed> 仓储保存输入 */
    private static function editorSave(array $values): array
    {
        $split = static fn (string $v): array => array_values(array_filter(
            array_map('trim', explode(',', $v)),
            static fn (string $s): bool => $s !== '',
        ));
        return [
            'title' => $values['title'],
            'slug' => $values['slug'],
            'bodyMd' => $values['bodyMd'],
            'excerpt' => $values['excerpt'] === '' ? null : $values['excerpt'],
            'status' => $values['status'],
            'categories' => $split($values['categories']),
            'tags' => $split($values['tags']),
        ];
    }

    /** @return array<string, string> */
    private static function editorFromPost(Post $post): array
    {
        return [
            'title' => $post->title(),
            'slug' => $post->slug(),
            'bodyMd' => $post->bodyMd(),
            'excerpt' => (string) ($post->excerpt() ?? ''),
            'status' => $post->status(),
            'categories' => implode(', ', $post->categories()),
            'tags' => implode(', ', $post->tags()),
        ];
    }

    /** @param array<string, mixed> $values @return ?string */
    private static function editorValidate(array $values): ?string
    {
        if (strlen((string) $values['bodyMd']) > Markdown::MAX_INPUT) {
            return '正文超过 200KB 上限,请拆分内容';
        }
        if ($values['status'] === 'published' && $values['title'] === '') {
            return '发布前必须填写标题';
        }
        return null;
    }

    /** @param array<string, mixed> $data */
    private static function editorPage(Kernel $kernel, ServerRequestInterface $req, array $data, int $status = 200): ResponseInterface
    {
        return self::page($kernel, $req, 'admin_editor', $data, 'dashboard', $data['heading'] . ' · 后台', 'admin', $status);
    }

    /** 主题 404 页(Kernel 在非 API 路由未匹配时调用)。 */
    public static function notFound(Kernel $kernel, ServerRequestInterface $req): ResponseInterface
    {
        return self::page($kernel, $req, '404', [], null, '页面不存在', 'site', 404);
    }

    /** 子系统后台页渲染入口(04 §8 插件页复用主题 layout)。 */
    public static function renderAdmin(Kernel $kernel, ServerRequestInterface $req, string $tpl, array $data, ?string $activePage, ?string $title = null): ResponseInterface
    {
        return self::page($kernel, $req, $tpl, $data, $activePage, $title, 'admin');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function page(
        Kernel $kernel,
        ServerRequestInterface $req,
        string $tpl,
        array $data,
        ?string $activePage,
        ?string $title = null,
        string $shell = 'site',
        int $status = 200,
    ): ResponseInterface {
        $manager = $kernel->theme();
        $active = $manager->active();
        $siteTitle = (string) $kernel->options()->get('site.title', 'PenBlog');
        $gate = AuthMiddleware::gate($req);
        $content = View::render($manager->templatePath($active, $tpl), $data + [
            'siteTitle' => $siteTitle,
            'csrf' => self::csrf($req),
            'theme' => $manager,
            'themeName' => $active,
        ]);
        $bellItems = [];
        $bellUnread = 0;
        if ($shell === 'admin' && $gate->isAuthenticated()) {
            try {
                $notifs = $kernel->container->get(\PenBlog\Notification\NotificationRepository::class);
                $bellItems = $notifs->list($gate->user->id(), [], 1, 8)['items'];
                $bellUnread = $notifs->unreadCount($gate->user->id());
            } catch (\Throwable) {
                // 通知子系统不可用不影响页面渲染
            }
        }
        // MenuItem 扩展点(04 §4):插件注册的菜单项注入 layout
        $pluginMenu = $shell === 'admin' ? $kernel->plugins()->menuItems('admin') : [];
        $siteMenu = $shell === 'site' ? $kernel->plugins()->menuItems('nav') : [];
        $html = View::render($manager->templatePath($active, 'layout'), [
            'content' => $content,
            'title' => $title !== null ? "{$title} · {$siteTitle}" : $siteTitle,
            'siteTitle' => $siteTitle,
            'shell' => $shell,
            'page' => $activePage,
            'authed' => $gate->isAuthenticated(),
            'adminUser' => $gate->user?->displayName() ?? '',
            'adminLevelTitle' => $gate->title,
            'csrf' => self::csrf($req),
            'bellItems' => $bellItems,
            'bellUnread' => $bellUnread,
            'pluginMenu' => $pluginMenu,
            'siteMenu' => $siteMenu,
            'tokensCss' => $manager->tokensCss($active),
            'footerText' => (string) $manager->activeOption('footer_text', ''),
        ]);
        return $kernel->html($html, $status);
    }

    private static function loginPage(Kernel $kernel, ServerRequestInterface $req, ?string $error, int $status = 200): ResponseInterface
    {
        return self::page($kernel, $req, 'admin_login', ['error' => $error], null, '登录', 'site', $status);
    }

    /** @param list<string> $usernames @return array<string,string> 用户名=>显示名 */
    private static function authorNames(Kernel $kernel, array $usernames): array
    {
        $users = $kernel->container->get(UserRepository::class);
        $out = [];
        foreach (array_unique($usernames) as $name) {
            $user = $users->findByUsername($name);
            $out[$name] = $user?->displayName() ?? $name;
        }
        return $out;
    }

    private static function csrf(ServerRequestInterface $req): string
    {
        return (string) ($req->getCookieParams()[SessionStore::CSRF_COOKIE] ?? '');
    }

    private static function postId(\PDO $pdo, string $slug): int
    {
        $stmt = $pdo->prepare('SELECT id FROM posts WHERE slug = :s AND deleted_at IS NULL');
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw \PenBlog\Api\ApiException::notFound('Post');
        }
        return (int) $row['id'];
    }

    /** @return list<string> */
    private static function authCookies(string $raw, string $csrf, bool $remember): array
    {
        $ttl = $remember ? SessionStore::REMEMBER_TTL_SECONDS : SessionStore::TTL_SECONDS;
        return [
            sprintf('%s=%s; Path=/; HttpOnly; SameSite=Lax%s', SessionStore::COOKIE, $raw, $remember ? '; Max-Age=' . $ttl : ''),
            sprintf('%s=%s; Path=/; SameSite=Lax; Max-Age=%d', SessionStore::CSRF_COOKIE, $csrf, $ttl),
        ];
    }

    private static function expiredCookie(string $name): string
    {
        return sprintf('%s=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0', $name);
    }
}
