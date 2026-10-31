<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

/**
 * 列表查询参数(06 §4 的 M1 子集):page/size/sort/filter + cursor。
 */
final class ListQuery
{
    public const MAX_SIZE = 100;

    /**
     * @param array<string, string> $sort       字段 => asc|desc("-"前缀语法已在外层解析)
     * @param array<string, mixed>  $filters    字段 => 值(等值;range 用 字段_gte/字段_lte)
     * @param list<string>          $sortable   服务端白名单
     * @param list<string>          $filterable 服务端白名单
     */
    private function __construct(
        public readonly int $page,
        public readonly int $size,
        public readonly array $sort,
        public readonly array $filters,
        public readonly ?int $offsetCursor,
    ) {
    }

    /** @param array<string, mixed> $params */
    public static function fromParams(array $params, array $sortable = ['name', 'creation_timestamp'], array $filterable = ['name']): self
    {
        $size = min(max((int) ($params['size'] ?? 20), 1), self::MAX_SIZE);
        $page = max((int) ($params['page'] ?? 1), 1);

        $sort = [];
        foreach ((array) ($params['sort'] ?? []) as $field) {
            $dir = str_starts_with($field, '-') ? 'desc' : 'asc';
            $name = ltrim($field, '-');
            if (in_array($name, $sortable, true)) {
                $sort[$name] = $dir;
            }
        }
        if ($sort === []) {
            $sort = ['creation_timestamp' => 'desc'];
        }

        $filters = [];
        foreach ((array) ($params['filter'] ?? []) as $key => $value) {
            $column = preg_replace('/_(gte|lte)$/', '', (string) $key) ?? (string) $key;
            if (in_array($column, $filterable, true)) {
                $filters[(string) $key] = $value;
            }
        }

        $cursor = null;
        if (isset($params['cursor']) && $params['cursor'] !== '') {
            $decoded = json_decode(base64_decode((string) $params['cursor'], true) ?: '', true);
            if (is_array($decoded) && isset($decoded['offset'])) {
                $cursor = max((int) $decoded['offset'], 0);
            }
        }

        return new self($page, $size, $sort, $filters, $cursor);
    }

    public function offset(): int
    {
        return $this->offsetCursor ?? ($this->page - 1) * $this->size;
    }

    public static function encodeCursor(int $offset): string
    {
        return base64_encode(json_encode(['offset' => $offset]));
    }
}
