<?php

declare(strict_types=1);

namespace PenBlog\Api;

use Nyholm\Psr7\Response;
use PenBlog\Kernel\Extension;
use PenBlog\Kernel\Page;
use Psr\Http\Message\ResponseInterface;

/**
 * 06 §1/§4/§5 的响应工具:统一 JSON、错误体、稀疏字段(fields=)。
 */
final class Json
{
    /** @param array<string, mixed> $data @param array<string,string> $headers */
    public static function ok(int $status, array $data, array $headers = []): ResponseInterface
    {
        return self::send($status, $data, $headers);
    }

    /** @param list<array{field?:string, reason?:string}> $details @param array<string,string> $headers */
    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        $error['requestId'] = 'req_' . bin2hex(random_bytes(6));
        return self::send($status, ['error' => $error], $headers);
    }

    public static function item(Extension $e, ?array $fields = null): array
    {
        $arr = $e->toArray();
        return $fields === null ? $arr : self::sparse($arr, $fields);
    }

    /** @param array<string, mixed> $row */
    public static function list(Page $page, ?array $fields = null): array
    {
        return [
            'items' => array_map(static fn (Extension $e): array => self::item($e, $fields), $page->items),
            'page' => $page->page,
            'size' => $page->size,
            'total' => $page->total,
            'nextCursor' => $page->nextCursor,
        ];
    }

    /** 06 §4 fields=metadata.name,spec.title —— 点路径白名单裁剪。 */
    public static function sparse(array $arr, array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $value = $arr;
            $ok = true;
            foreach (explode('.', $path) as $seg) {
                if (!is_array($value) || !array_key_exists($seg, $value)) {
                    $ok = false;
                    break;
                }
                $value = $value[$seg];
            }
            if ($ok) {
                $out[$path] = $value;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $data @param array<string,string> $headers */
    private static function send(int $status, array $data, array $headers): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
