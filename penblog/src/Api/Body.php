<?php

declare(strict_types=1);

namespace PenBlog\Api;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 请求体读取:JSON 优先(parsedBody 由 BodyParserMiddleware 注入),表单兜底。
 */
final class Body
{
    /** @return array<string, mixed> */
    public static function of(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string> 路由参数 */
    public static function routeParams(ServerRequestInterface $request): array
    {
        $params = $request->getAttribute('route_params', []);
        return is_array($params) ? $params : [];
    }

    /** @return list<string>|null 稀疏字段,无则 null */
    public static function fields(ServerRequestInterface $request): ?array
    {
        $query = $request->getQueryParams();
        $fields = $query['fields'] ?? null;
        if (!is_string($fields) || $fields === '') {
            return null;
        }
        return array_values(array_filter(array_map('trim', explode(',', $fields))));
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public static function listQuery(array $params): array
    {
        $query = [];
        if (isset($params['page'])) {
            $query['page'] = (int) $params['page'];
        }
        if (isset($params['size'])) {
            $query['size'] = (int) $params['size'];
        }
        if (isset($params['cursor'])) {
            $query['cursor'] = (string) $params['cursor'];
        }
        if (isset($params['sort'])) {
            $query['sort'] = is_string($params['sort']) ? explode(',', $params['sort']) : (array) $params['sort'];
        }
        if (isset($params['filter']) && is_array($params['filter'])) {
            $query['filter'] = $params['filter'];
        }
        return $query;
    }
}
