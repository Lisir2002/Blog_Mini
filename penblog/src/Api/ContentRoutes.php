<?php

declare(strict_types=1);

namespace PenBlog\Api;

use PenBlog\Content\Post;
use PenBlog\Content\PostRepository;
use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Kernel;
use PenBlog\Kernel\ListQuery;
use PenBlog\Kernel\Metadata;
use PenBlog\Support\Slugger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 内容 API(06 §3):posts/comments/categories/tags/pages。
 */
final class ContentRoutes
{
    private const POST_SORTABLE = ['title', 'created_at', 'updated_at', 'published_at'];
    private const POST_FILTERABLE = ['status', 'author', 'category', 'tag', 'created_at', 'updated_at', 'published_at'];

    public static function register(Kernel $kernel): void
    {
        $c = $kernel->container;
        $router = $kernel->router;
        $posts = static fn (): PostRepository => $c->get(PostRepository::class);

        // ---- posts ----
        $router->get('/api/v1/posts', static function (ServerRequestInterface $req) use ($posts): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            $query = ListQuery::fromParams(
                Body::listQuery($req->getQueryParams()),
                self::POST_SORTABLE,
                self::POST_FILTERABLE,
            );
            return Json::ok(200, Json::list($posts()->query($query, $gate), Body::fields($req)));
        });

        $router->post('/api/v1/posts', static function (ServerRequestInterface $req) use ($posts): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            if (!$gate->allows('post:write') && !$gate->allows('post:manage')) {
                throw ApiException::forbidden('需要权限 post:write');
            }
            $post = $posts()->save(null, Body::of($req), $gate);
            return Json::ok(201, Json::item($post));
        });

        $router->get('/api/v1/posts/{slug}', static function (ServerRequestInterface $req) use ($posts): ResponseInterface {
            $slug = rawurldecode((string) (Body::routeParams($req)['slug'] ?? ''));
            $post = $posts()->get($slug, AuthMiddleware::gate($req));
            return Json::ok(200, Json::item($post, Body::fields($req)));
        });

        $router->put('/api/v1/posts/{slug}', static function (ServerRequestInterface $req) use ($posts): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $gate = AuthMiddleware::gate($req);
            $author = $posts()->authorOf($slug) ?? throw ApiException::notFound('Post');
            if (!$gate->canWritePost($author)) {
                throw ApiException::forbidden('无权修改该文章');
            }
            $post = $posts()->save($slug, Body::of($req), $gate);
            return Json::ok(200, Json::item($post));
        });

        $router->delete('/api/v1/posts/{slug}', static function (ServerRequestInterface $req) use ($posts): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $gate = AuthMiddleware::gate($req);
            $author = $posts()->authorOf($slug) ?? throw ApiException::notFound('Post');
            if (!$gate->canWritePost($author)) {
                throw ApiException::forbidden('无权删除该文章');
            }
            $posts()->delete($slug);
            return Json::ok(200, ['ok' => true]);
        });

        // 自定义方法 :publish(06 §3)
        $router->post('/api/v1/posts/{slug}:publish', static function (ServerRequestInterface $req) use ($posts): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $gate = AuthMiddleware::gate($req);
            $author = $posts()->authorOf($slug) ?? throw ApiException::notFound('Post');
            if (!$gate->canWritePost($author)) {
                throw ApiException::forbidden('无权发布该文章');
            }
            $post = $posts()->save($slug, ['status' => 'published'], $gate);
            return Json::ok(200, Json::item($post));
        });

        // ---- comments ----
        $router->get('/api/v1/posts/{slug}/comments', static function (ServerRequestInterface $req) use ($c, $posts): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $gate = AuthMiddleware::gate($req);
            $post = $posts()->get($slug, $gate);
            $pdo = $c->get(\PenBlog\Repository\SqlDriver::class)->pdo();
            $statuses = $gate->allows('comment:moderate') ? "IN ('pending','approved')" : "= 'approved'";
            $stmt = $pdo->prepare(
                "SELECT cm.*, u.username, u.display_name FROM comments cm
                 LEFT JOIN users u ON u.id = cm.user_id
                 WHERE cm.post_id = :p AND cm.status {$statuses} ORDER BY cm.created_at ASC LIMIT 500"
            );
            $stmt->execute(['p' => self::postId($pdo, $post->slug())]);
            $items = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'body' => (string) $r['body'],
                'status' => (string) $r['status'],
                'createdAt' => (string) $r['created_at'],
                'author' => $r['username'] !== null
                    ? ['username' => (string) $r['username'], 'displayName' => (string) $r['display_name']]
                    : ['username' => null, 'displayName' => (string) ($r['author_name'] ?? '匿名')],
            ], $stmt->fetchAll());
            return Json::ok(200, ['items' => $items]);
        });

        $router->post('/api/v1/posts/{slug}/comments', static function (ServerRequestInterface $req) use ($c, $posts): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $gate = AuthMiddleware::gate($req);
            if (!$gate->allows('comment:create') && !$gate->allows('comment:moderate')) {
                throw ApiException::forbidden('需要权限 comment:create');
            }
            $post = $posts()->get($slug, $gate);
            $body = trim((string) (Body::of($req)['body'] ?? ''));
            if ($body === '') {
                throw ValidationException::required('body');
            }
            $pdo = $c->get(\PenBlog\Repository\SqlDriver::class)->pdo();
            $id = $c->get(\PenBlog\Repository\SqlDriver::class)->insertReturningId('comments', [
                'post_id' => self::postId($pdo, $post->slug()),
                'user_id' => $gate->user->id(),
                'author_name' => null,
                'author_email' => null,
                'parent_id' => isset(Body::of($req)['parentId']) ? (int) Body::of($req)['parentId'] : null,
                'body' => $body,
                'status' => $gate->allows('comment:moderate') ? 'approved' : 'pending',
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            return Json::ok(201, ['comment' => ['id' => $id, 'status' => $gate->allows('comment:moderate') ? 'approved' : 'pending']]);
        });

        // ---- categories / tags ----
        foreach (['categories', 'tags'] as $term) {
            $router->get("/api/v1/{$term}", static function (ServerRequestInterface $req) use ($c, $term): ResponseInterface {
                $pdo = $c->get(\PenBlog\Repository\SqlDriver::class)->pdo();
                $countCol = $term === 'categories' ? 'post_categories' : 'post_tags';
                $fk = $term === 'categories' ? 'category_id' : 'tag_id';
                $extra = $term === 'categories' ? ', t.description' : '';
                $stmt = $pdo->query(
                    "SELECT t.id, t.name, t.slug{$extra},
                            (SELECT COUNT(*) FROM {$countCol} l WHERE l.{$fk} = t.id) AS post_count
                     FROM {$term} t ORDER BY t.name ASC"
                );
                $items = array_map(static fn (array $r): array => array_filter([
                    'id' => (int) $r['id'],
                    'name' => (string) $r['name'],
                    'slug' => (string) $r['slug'],
                    'description' => $r['description'] ?? null,
                    'postCount' => (int) $r['post_count'],
                ], static fn ($v): bool => $v !== null), $stmt->fetchAll());
                return Json::ok(200, ['items' => $items]);
            });

            $router->post("/api/v1/{$term}", static function (ServerRequestInterface $req) use ($c, $term): ResponseInterface {
                AuthMiddleware::gate($req)->require('post:manage');
                $body = Body::of($req);
                $name = trim((string) ($body['name'] ?? ''));
                if ($name === '') {
                    throw ValidationException::required('name');
                }
                $driver = $c->get(\PenBlog\Repository\SqlDriver::class);
                $pdo = $driver->pdo();
                $slug = isset($body['slug']) && trim((string) $body['slug']) !== '' ? Slugger::fromTitle((string) $body['slug']) : Slugger::fromTitle($name);
                $stmt = $pdo->prepare("SELECT 1 FROM {$term} WHERE slug = :s OR name = :n");
                $stmt->execute(['s' => $slug, 'n' => $name]);
                if ($stmt->fetch() !== false) {
                    throw ApiException::conflict("{$term} 已存在: {$name}");
                }
                $id = $driver->insertReturningId($term, [
                    'name' => $name,
                    'slug' => $slug,
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ...($term === 'categories' ? ['description' => isset($body['description']) ? (string) $body['description'] : null] : []),
                ]);
                return Json::ok(201, ['id' => $id, 'name' => $name, 'slug' => $slug]);
            });

            $router->put("/api/v1/{$term}/{name}", static function (ServerRequestInterface $req) use ($c, $term): ResponseInterface {
                AuthMiddleware::gate($req)->require('post:manage');
                $old = (string) (Body::routeParams($req)['name'] ?? '');
                $body = Body::of($req);
                $driver = $c->get(\PenBlog\Repository\SqlDriver::class);
                $pdo = $driver->pdo();
                $stmt = $pdo->prepare("SELECT id FROM {$term} WHERE name = :n");
                $stmt->execute(['n' => $old]);
                $row = $stmt->fetch();
                if ($row === false) {
                    throw ApiException::notFound(ucfirst($term));
                }
                $set = [];
                $bind = ['id' => (int) $row['id']];
                if (isset($body['name'])) {
                    $set[] = 'name = :name';
                    $bind['name'] = trim((string) $body['name']);
                }
                if (isset($body['slug'])) {
                    $set[] = 'slug = :slug';
                    $bind['slug'] = Slugger::fromTitle((string) $body['slug']);
                }
                if ($term === 'categories' && array_key_exists('description', $body)) {
                    $set[] = 'description = :desc';
                    $bind['desc'] = $body['description'] === null ? null : (string) $body['description'];
                }
                if ($set !== []) {
                    $pdo->prepare("UPDATE {$term} SET " . implode(', ', $set) . ' WHERE id = :id')->execute($bind);
                }
                return Json::ok(200, ['ok' => true]);
            });

            $router->delete("/api/v1/{$term}/{name}", static function (ServerRequestInterface $req) use ($c, $term): ResponseInterface {
                AuthMiddleware::gate($req)->require('post:manage');
                $name = (string) (Body::routeParams($req)['name'] ?? '');
                $pdo = $c->get(\PenBlog\Repository\SqlDriver::class)->pdo();
                $stmt = $pdo->prepare("DELETE FROM {$term} WHERE name = :n");
                $stmt->execute(['n' => $name]);
                if ($stmt->rowCount() === 0) {
                    throw ApiException::notFound(ucfirst($term));
                }
                return Json::ok(200, ['ok' => true]);
            });
        }

        // ---- pages(仅 extensions,无投影表)----
        $router->get('/api/v1/pages', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            $page = $kernel->extensions()->query('Page', ListQuery::fromParams(Body::listQuery($req->getQueryParams()), ['name'], ['name']));
            $items = array_values(array_filter(
                $page->items,
                static fn ($p): bool => $gate->allows('page:manage') || $p->isPublished(),
            ));
            return Json::ok(200, ['items' => array_map(static fn ($p): array => Json::item($p), $items)]);
        });

        $router->post('/api/v1/pages', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            AuthMiddleware::gate($req)->require('page:manage');
            $body = Body::of($req);
            $title = trim((string) ($body['title'] ?? ''));
            if ($title === '') {
                throw ValidationException::required('title');
            }
            $slug = Slugger::fromTitle(isset($body['slug']) && trim((string) $body['slug']) !== '' ? (string) $body['slug'] : $title);
            $spec = self::pageSpec($body, $title, $slug);
            $saved = $kernel->extensions()->register(new \PenBlog\Content\Page(Metadata::generate($slug, 'Page'), $spec));
            return Json::ok(201, Json::item($saved));
        });

        $router->get('/api/v1/pages/{slug}', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $found = $kernel->extensions()->find('Page', $slug);
            if (!$found instanceof \PenBlog\Content\Page) {
                throw ApiException::notFound('Page');
            }
            $gate = AuthMiddleware::gate($req);
            if (!$found->isPublished() && !$gate->allows('page:manage')) {
                throw ApiException::notFound('Page');
            }
            return Json::ok(200, Json::item($found, Body::fields($req)));
        });

        $router->put('/api/v1/pages/{slug}', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            AuthMiddleware::gate($req)->require('page:manage');
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            $found = $kernel->extensions()->find('Page', $slug);
            if (!$found instanceof \PenBlog\Content\Page) {
                throw ApiException::notFound('Page');
            }
            $body = Body::of($req);
            $title = trim((string) ($body['title'] ?? $found->title()));
            $newSlug = isset($body['slug']) && trim((string) $body['slug']) !== '' ? Slugger::fromTitle((string) $body['slug']) : $slug;
            $expected = isset($body['metadata']['resourceVersion']) ? (int) $body['metadata']['resourceVersion'] : null;
            if ($expected !== null && $expected !== $found->metadata->resourceVersion) {
                throw ApiException::conflict('资源已被他人修改,请刷新后重试', 'resource_version_conflict');
            }
            $meta = $newSlug === $slug
                ? $found->metadata
                : new Metadata(name: $newSlug, uid: $found->metadata->uid, kind: 'Page', apiVersion: $found->metadata->apiVersion, creationTimestamp: $found->metadata->creationTimestamp);
            $saved = $kernel->extensions()->register(new \PenBlog\Content\Page($meta, self::pageSpec($body, $title, $newSlug, $found)));
            if ($newSlug !== $slug) {
                $kernel->extensions()->delete('Page', $slug);
            }
            return Json::ok(200, Json::item($saved));
        });

        $router->delete('/api/v1/pages/{slug}', static function (ServerRequestInterface $req) use ($kernel): ResponseInterface {
            AuthMiddleware::gate($req)->require('page:manage');
            $slug = (string) (Body::routeParams($req)['slug'] ?? '');
            if (!$kernel->extensions()->delete('Page', $slug)) {
                throw ApiException::notFound('Page');
            }
            return Json::ok(200, ['ok' => true]);
        });
    }

    /** @param array<string, mixed> $body @param \PenBlog\Content\Page|null $existing */
    private static function pageSpec(array $body, string $title, string $slug, ?object $existing = null): array
    {
        $status = (string) ($body['status'] ?? $existing?->status() ?? 'draft');
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new ValidationException([['field' => 'status', 'reason' => 'invalid']], '页面状态需为 draft/published');
        }
        return [
            'title' => $title,
            'slug' => $slug,
            'bodyMd' => (string) ($body['bodyMd'] ?? $existing?->bodyMd() ?? ''),
            'status' => $status,
        ];
    }

    private static function postId(\PDO $pdo, string $slug): int
    {
        $stmt = $pdo->prepare('SELECT id FROM posts WHERE slug = :s AND deleted_at IS NULL');
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ApiException::notFound('Post');
        }
        return (int) $row['id'];
    }
}
