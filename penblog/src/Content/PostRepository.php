<?php

declare(strict_types=1);

namespace PenBlog\Content;

use PenBlog\Api\ApiException;
use PenBlog\Api\ValidationException;
use PenBlog\Auth\Gate;
use PenBlog\Kernel\ExtensionRegistry;
use PenBlog\Kernel\ListQuery;
use PenBlog\Kernel\Metadata;
use PenBlog\Kernel\Page as PageResult;
use PenBlog\Repository\SqlDriver;
use PenBlog\Support\Slugger;

/**
 * 文章仓储(07 §2):extensions 文档 + posts 投影双写(事务内),
 * 分类/标签按名称自动建项并同步关联表;乐观并发经 metadata.resourceVersion。
 */
final class PostRepository
{
    private const STATUSES = ['draft', 'published', 'archived'];

    public function __construct(
        private readonly SqlDriver $driver,
        private readonly ExtensionRegistry $registry,
        private readonly \PenBlog\Kernel\EventBus $events,
    ) {
    }

    /**
     * @param array<string, mixed> $input spec 字段:title/bodyMd/excerpt/slug/status/author/categories/tags/metadata.resourceVersion
     */
    public function save(?string $slug, array $input, Gate $gate): Post
    {
        $existing = null;
        $wasPublished = false;
        if ($slug !== null) {
            $found = $this->registry->find(Post::KIND, $slug);
            if (!$found instanceof Post) {
                throw ApiException::notFound('Post');
            }
            $existing = $found;
            $wasPublished = $found->status() === 'published';
        }

        $title = trim((string) ($input['title'] ?? $existing?->title() ?? ''));
        if ($title === '') {
            throw ValidationException::required('title');
        }

        // 乐观并发(06 §3)
        $expected = isset($input['metadata']['resourceVersion']) ? (int) $input['metadata']['resourceVersion'] : null;
        if ($expected !== null && $existing !== null && $expected !== $existing->metadata->resourceVersion) {
            throw ApiException::conflict('资源已被他人修改,请刷新后重试', 'resource_version_conflict');
        }

        $status = (string) ($input['status'] ?? $existing?->status() ?? 'draft');
        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException([['field' => 'status', 'reason' => 'invalid']], '状态需为 draft/published/archived');
        }

        // 作者:L0/L1 可指定,其余强制本人(09 §1)
        if (!$gate->allows('post:manage')) {
            $gate->requireAuth();
            $author = $gate->user->username();
        } elseif (isset($input['author']) && trim((string) $input['author']) !== '') {
            $author = trim((string) $input['author']);
        } else {
            $author = (string) ($existing?->author() ?? $gate->user?->username() ?? '');
        }
        if ($author === '' || $this->userId($author) === null) {
            throw new ValidationException([['field' => 'author', 'reason' => 'unknown']], "作者不存在: {$author}");
        }

        $base = isset($input['slug']) && trim((string) $input['slug']) !== ''
            ? Slugger::fromTitle((string) $input['slug'])
            : ($existing?->slug() ?? Slugger::fromTitle($title));
        $finalSlug = Slugger::unique($base, fn (string $s): bool => $this->slugTaken($s, $existing?->metadata->uid ?? ''));

        $publishedAt = $existing?->publishedAt();
        if ($status === 'published' && ($publishedAt === null || $publishedAt === '')) {
            $publishedAt = gmdate('Y-m-d\TH:i:s\Z');
        }

        $spec = [
            'title' => $title,
            'bodyMd' => (string) ($input['bodyMd'] ?? $existing?->bodyMd() ?? ''),
            'excerpt' => array_key_exists('excerpt', $input)
                ? ($input['excerpt'] === null ? null : mb_substr((string) $input['excerpt'], 0, 500))
                : ($existing?->excerpt() ?? null),
            'status' => $status,
            'author' => $author,
            'categories' => array_values(array_map('strval', (array) ($input['categories'] ?? $existing?->categories() ?? []))),
            'tags' => array_values(array_map('strval', (array) ($input['tags'] ?? $existing?->tags() ?? []))),
            'publishedAt' => $publishedAt,
        ];

        $pdo = $this->driver->pdo();
        $wasTransaction = $pdo->inTransaction();
        if (!$wasTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($existing === null) {
                $meta = Metadata::generate($finalSlug, Post::KIND);
            } elseif ($finalSlug !== $existing->slug()) {
                // 改名:复用 uid,注册新名文档,删旧名文档
                $meta = new Metadata(
                    name: $finalSlug,
                    uid: $existing->metadata->uid,
                    kind: Post::KIND,
                    apiVersion: $existing->metadata->apiVersion,
                    creationTimestamp: $existing->metadata->creationTimestamp,
                );
            } else {
                $meta = $existing->metadata;
            }
            /** @var Post $post */
            $post = $this->registry->register(new Post($meta, $spec));
            if ($existing !== null && $finalSlug !== $existing->slug()) {
                $this->registry->delete(Post::KIND, $existing->slug());
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $stmt = $pdo->prepare('SELECT id FROM posts WHERE uid = :u');
            $stmt->execute(['u' => $post->metadata->uid]);
            $row = $stmt->fetch();
            if ($row === false) {
                $postId = $this->driver->insertReturningId('posts', [
                    'uid' => $post->metadata->uid,
                    'user_id' => $this->userId($author),
                    'title' => $title,
                    'slug' => $finalSlug,
                    'body_md' => $spec['bodyMd'],
                    'body_html' => null,
                    'excerpt' => $spec['excerpt'],
                    'status' => $status,
                    'published_at' => $publishedAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $postId = (int) $row['id'];
                $pdo->prepare(
                    'UPDATE posts SET user_id = :uid_, title = :t, slug = :s, body_md = :b, excerpt = :e,
                     status = :st, published_at = :pa, updated_at = :ts WHERE id = :id'
                )->execute([
                    'uid_' => $this->userId($author), 't' => $title, 's' => $finalSlug,
                    'b' => $spec['bodyMd'], 'e' => $spec['excerpt'], 'st' => $status,
                    'pa' => $publishedAt, 'ts' => $now, 'id' => $postId,
                ]);
            }

            $this->syncTerms($postId, 'categories', $spec['categories']);
            $this->syncTerms($postId, 'tags', $spec['tags']);

            if (!$wasTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$wasTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // 首次进入 published → 领域事件(05 §3;监听方吞异常,不影响发布)
        if ($status === 'published' && !$wasPublished) {
            $this->events->dispatch(new \PenBlog\Notification\Events\PostPublished($finalSlug, $title, $author));
        }

        return $post;
    }

    /** 列表查询:投影表过滤 + 批量 hydrate 文档(06 §4)。 */
    public function query(ListQuery $query, Gate $gate): PageResult
    {
        $where = ['p.deleted_at IS NULL'];
        $bind = [];
        if (!$gate->allows('post:manage')) {
            if ($gate->isAuthenticated()) {
                $where[] = "(p.status = 'published' OR p.user_id = :me)";
                $bind['me'] = $gate->user->id();
            } else {
                $where[] = "p.status = 'published'";
            }
        }

        $sortable = ['title' => 'p.title', 'created_at' => 'p.created_at', 'updated_at' => 'p.updated_at', 'published_at' => 'p.published_at'];
        $order = [];
        foreach ($query->sort as $field => $dir) {
            if (isset($sortable[$field])) {
                $order[] = $sortable[$field] . ' ' . ($dir === 'desc' ? 'DESC' : 'ASC');
            }
        }
        if ($order === []) {
            $order = ['COALESCE(p.published_at, p.created_at) DESC'];
        }

        foreach ($query->filters as $key => $value) {
            $col = preg_replace('/_(gte|lte)$/', '', (string) $key) ?? (string) $key;
            $op = str_ends_with((string) $key, '_gte') ? '>=' : (str_ends_with((string) $key, '_lte') ? '<=' : '=');
            $ph = 'v_' . preg_replace('/\W/', '_', (string) $key);
            switch ($col) {
                case 'status':
                    if ($gate->allows('post:manage')) {
                        $where[] = "p.status {$op} :{$ph}";
                        $bind[$ph] = (string) $value;
                    }
                    break;
                case 'author':
                    $where[] = "p.user_id = (SELECT id FROM users WHERE username = :{$ph})";
                    $bind[$ph] = (string) $value;
                    break;
                case 'category':
                    $where[] = "EXISTS (SELECT 1 FROM post_categories pc JOIN categories c ON c.id = pc.category_id
                             WHERE pc.post_id = p.id AND c.slug = :{$ph})";
                    $bind[$ph] = Slugger::fromTitle((string) $value);
                    break;
                case 'tag':
                    $where[] = "EXISTS (SELECT 1 FROM post_tags pt JOIN tags t ON t.id = pt.tag_id
                             WHERE pt.post_id = p.id AND t.slug = :{$ph})";
                    $bind[$ph] = Slugger::fromTitle((string) $value);
                    break;
                case 'created_at':
                case 'updated_at':
                case 'published_at':
                    $where[] = "p.{$col} {$op} :{$ph}";
                    $bind[$ph] = (string) $value;
                    break;
            }
        }

        $whereSql = implode(' AND ', $where);
        $pdo = $this->driver->pdo();
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM posts p WHERE {$whereSql}");
        $stmt->execute($bind);
        $total = (int) $stmt->fetch()['c'];

        $stmt = $pdo->prepare(
            "SELECT uid FROM posts p WHERE {$whereSql} ORDER BY " . implode(', ', $order)
            . ' LIMIT ' . $query->size . ' OFFSET ' . $query->offset()
        );
        $stmt->execute($bind);
        $uids = array_column($stmt->fetchAll(), 'uid');
        $items = $this->hydrateMany($uids);

        $offset = $query->offset();
        $next = $offset + count($items) < $total ? ListQuery::encodeCursor($offset + $query->size) : null;
        return new PageResult($items, intdiv($offset, $query->size) + 1, $query->size, $total, $next);
    }

    /** 按 slug 取单篇;不可见草稿按 404 处理(06 §3)。 */
    public function get(string $slug, Gate $gate): Post
    {
        $stmt = $this->driver->pdo()->prepare('SELECT status, user_id FROM posts WHERE slug = :s AND deleted_at IS NULL');
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw ApiException::notFound('Post');
        }
        $found = $this->registry->find(Post::KIND, $slug);
        if (!$found instanceof Post) {
            throw ApiException::notFound('Post');
        }
        if (!$found->isPublished() && !$gate->allows('post:manage')
            && !($gate->isAuthenticated() && $gate->user->username() === $found->author())) {
            throw ApiException::notFound('Post');
        }
        return $found;
    }

    /** 主题用:按 slug 取已发布文章,不可见返回 null。 */
    public function findPublished(string $slug): ?Post
    {
        $found = $this->registry->find(Post::KIND, $slug);
        return $found instanceof Post && $found->isPublished() ? $found : null;
    }

    /** 软删投影行 + 删除文档;返回作者名供路由做权限判定。 */
    public function delete(string $slug): ?string
    {
        $stmt = $this->driver->pdo()->prepare('SELECT id, user_id FROM posts WHERE slug = :s AND deleted_at IS NULL');
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $found = $this->registry->find(Post::KIND, $slug);
        $this->driver->pdo()->prepare('UPDATE posts SET deleted_at = :t WHERE id = :id')
            ->execute(['t' => gmdate('Y-m-d\TH:i:s\Z'), 'id' => (int) $row['id']]);
        $this->registry->delete(Post::KIND, $slug);
        return $found instanceof Post ? $found->author() : null;
    }

    public function authorOf(string $slug): ?string
    {
        $found = $this->registry->find(Post::KIND, $slug);
        return $found instanceof Post ? $found->author() : null;
    }

    /** @param list<string> $names */
    private function syncTerms(int $postId, string $kind, array $names): void
    {
        $table = $kind === 'categories' ? 'post_categories' : 'post_tags';
        $termTable = $kind;
        $col = $kind === 'categories' ? 'category_id' : 'tag_id';
        $pdo = $this->driver->pdo();
        $pdo->prepare("DELETE FROM {$table} WHERE post_id = :p")->execute(['p' => $postId]);
        foreach (array_unique($names) as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $slug = Slugger::fromTitle($name);
            $stmt = $pdo->prepare("SELECT id FROM {$termTable} WHERE slug = :s");
            $stmt->execute(['s' => $slug]);
            $row = $stmt->fetch();
            $termId = $row === false
                ? $this->driver->insertReturningId($termTable, [
                    'name' => $name, 'slug' => Slugger::unique($slug, fn (string $s): bool => $this->termTaken($termTable, $s)),
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    ...($termTable === 'categories' ? ['description' => null] : []),
                ])
                : (int) $row['id'];
            $pdo->prepare("INSERT INTO {$table} (post_id, {$col}) VALUES (:p, :t)")->execute(['p' => $postId, 't' => $termId]);
        }
    }

    private function termTaken(string $table, string $slug): bool
    {
        $stmt = $this->driver->pdo()->prepare("SELECT 1 FROM {$table} WHERE slug = :s");
        $stmt->execute(['s' => $slug]);
        return $stmt->fetch() !== false;
    }

    private function slugTaken(string $slug, string $selfUid): bool
    {
        $stmt = $this->driver->pdo()->prepare('SELECT 1 FROM posts WHERE slug = :s AND deleted_at IS NULL AND uid != :u');
        $stmt->execute(['s' => $slug, 'u' => $selfUid]);
        return $stmt->fetch() !== false;
    }

    private function userId(string $username): ?int
    {
        $stmt = $this->driver->pdo()->prepare('SELECT id FROM users WHERE username = :u');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : (int) $row['id'];
    }

    /** @param list<string> $uids @return list<Post> */
    private function hydrateMany(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($uids), '?'));
        $stmt = $this->driver->pdo()->prepare("SELECT * FROM extensions WHERE kind = 'Post' AND uid IN ({$in})");
        $stmt->execute($uids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            /** @var Post $post */
            $post = Post::fromArray([
                'name' => $row['name'], 'uid' => $row['uid'], 'kind' => $row['kind'],
                'apiVersion' => $row['api_version'], 'creationTimestamp' => $row['creation_timestamp'],
                'updateTimestamp' => $row['update_timestamp'], 'resourceVersion' => $row['resource_version'],
            ], json_decode((string) $row['spec'], true) ?: []);
            $out[(string) $row['uid']] = $post;
        }
        // 保持投影表排序
        $ordered = [];
        foreach ($uids as $uid) {
            if (isset($out[$uid])) {
                $ordered[] = $out[$uid];
            }
        }
        return $ordered;
    }
}
