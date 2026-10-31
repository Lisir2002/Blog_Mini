<?php

declare(strict_types=1);

namespace PenBlog\Menu;

use PenBlog\Api\ValidationException;
use PenBlog\Auth\UserRepository;
use PenBlog\Content\Page as PageDoc;
use PenBlog\Content\PostRepository;
use PenBlog\Kernel\Extension;
use PenBlog\Kernel\Kernel;
use PenBlog\Kernel\ListQuery;
use PenBlog\Kernel\Metadata;
use PenBlog\Support\Slugger;

/**
 * 菜单系统(03 §7):菜单是 Extension(kind: Menu,name=菜单位置),
 * spec.items 引用对象(post/page/category/author/url),主题渲染前解析为链接。
 * 目标对象失效(文章删除/草稿/分类不存在)时条目静默丢弃,不阻断渲染。
 */
final class MenuManager
{
    public const KIND = 'Menu';

    /** 条目类型白名单。 */
    public const TYPES = ['post', 'page', 'category', 'author', 'url'];

    private const LOCATION_RE = '/^[a-z][a-z0-9_-]{0,31}$/';
    private const MAX_ITEMS = 30;

    public function __construct(private readonly Kernel $kernel)
    {
    }

    /** @return list<array{location:string, items:list<array<string, mixed>>, resourceVersion:int}> */
    public function all(): array
    {
        $page = $this->kernel->extensions()->query(self::KIND, ListQuery::fromParams(['size' => 100], ['name'], ['name']));
        $out = [];
        foreach ($page->items as $ext) {
            /** @var Extension $ext */
            $out[] = [
                'location' => $ext->metadata->name,
                'items' => array_values((array) ($ext->spec['items'] ?? [])),
                'resourceVersion' => $ext->metadata->resourceVersion,
            ];
        }
        return $out;
    }

    public function get(string $location): ?Extension
    {
        $found = $this->kernel->extensions()->find(self::KIND, $location);
        return $found instanceof Extension ? $found : null;
    }

    /**
     * 校验并整表覆盖保存;resourceVersion 由注册中心递增。
     *
     * @param list<array<string, mixed>> $rawItems
     */
    public function save(string $location, array $rawItems): Extension
    {
        self::assertLocation($location);
        if (count($rawItems) > self::MAX_ITEMS) {
            throw new ValidationException([['field' => 'items', 'reason' => 'too_many']], '菜单条目最多 ' . self::MAX_ITEMS . ' 条');
        }
        $items = [];
        foreach (array_values($rawItems) as $i => $row) {
            $items[] = self::normalizeItem((array) $row, $i);
        }
        // PHP 8 稳定排序:sort 升序,同 sort 保持录入顺序
        usort($items, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $existing = $this->get($location);
        $meta = $existing !== null
            ? new Metadata(
                name: $location,
                uid: $existing->metadata->uid,
                kind: self::KIND,
                apiVersion: $existing->metadata->apiVersion,
                creationTimestamp: $existing->metadata->creationTimestamp,
            )
            : Metadata::generate($location, self::KIND);

        $saved = $this->kernel->extensions()->register(new Extension($meta, ['items' => $items]));
        $this->kernel->events->doAction('menu.saved', $location);
        return $saved;
    }

    public function delete(string $location): bool
    {
        self::assertLocation($location);
        return $this->kernel->extensions()->delete(self::KIND, $location);
    }

    /**
     * 渲染解析:逐条解析 href,失效条目丢弃;
     * primary 未配置/全部失效时回退默认"首页"链接,保证前台导航不空。
     *
     * @return list<array{label:string, href:string}>
     */
    public function resolve(string $location): array
    {
        $found = $this->get($location);
        $out = [];
        foreach ((array) ($found?->spec['items'] ?? []) as $item) {
            $item = (array) $item;
            $href = $this->href((string) ($item['type'] ?? ''), (string) ($item['target'] ?? ''));
            if ($href !== null) {
                $out[] = ['label' => (string) ($item['label'] ?? ''), 'href' => $href];
            }
        }
        if ($out === [] && $location === 'primary') {
            $out[] = ['label' => '首页', 'href' => '/'];
        }
        return $out;
    }

    private function href(string $type, string $target): ?string
    {
        if ($target === '') {
            return null;
        }
        return match ($type) {
            'post' => $this->kernel->container->get(PostRepository::class)->findPublished($target) !== null
                ? '/posts/' . rawurlencode($target) : null,
            'page' => $this->publishedPage($target) ? '/p/' . rawurlencode($target) : null,
            'author' => $this->kernel->container->get(UserRepository::class)->findByUsername($target) !== null
                ? '/authors/' . rawurlencode($target) : null,
            'category' => $this->categoryExists($target)
                ? '/?filter[category]=' . rawurlencode(Slugger::fromTitle($target)) : null,
            'url' => $target,
            default => null,
        };
    }

    private function publishedPage(string $slug): bool
    {
        $found = $this->kernel->extensions()->find(PageDoc::KIND, $slug);
        return $found instanceof PageDoc && $found->isPublished();
    }

    private function categoryExists(string $name): bool
    {
        $stmt = $this->kernel->db()->pdo()->prepare('SELECT 1 FROM categories WHERE slug = :s');
        $stmt->execute(['s' => Slugger::fromTitle($name)]);
        return $stmt->fetch() !== false;
    }

    /** @param array<string, mixed> $row */
    private static function normalizeItem(array $row, int $index): array
    {
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 64) {
            throw new ValidationException([['field' => "items.{$index}.label", 'reason' => 'invalid']], '菜单标签需为 1-64 个字符');
        }
        $type = trim((string) ($row['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException(
                [['field' => "items.{$index}.type", 'reason' => 'invalid']],
                '菜单条目类型需为 ' . implode('/', self::TYPES),
            );
        }
        $target = trim((string) ($row['target'] ?? ''));
        if ($target === '' || mb_strlen($target) > 255) {
            throw new ValidationException([['field' => "items.{$index}.target", 'reason' => 'invalid']], '菜单目标不能为空(≤255 字符)');
        }
        if ($type === 'url'
            && !str_starts_with($target, '/') && !preg_match('#^https?://#i', $target)) {
            throw new ValidationException(
                [['field' => "items.{$index}.target", 'reason' => 'invalid_url']],
                '外链需以 / 或 http(s):// 开头',
            );
        }
        return [
            'label' => $label,
            'type' => $type,
            'target' => $target,
            'sort' => isset($row['sort']) ? (int) $row['sort'] : 0,
        ];
    }

    private static function assertLocation(string $location): void
    {
        if (!preg_match(self::LOCATION_RE, $location)) {
            throw new ValidationException([['field' => 'location', 'reason' => 'invalid']], '菜单位置需匹配 ' . self::LOCATION_RE);
        }
    }
}
