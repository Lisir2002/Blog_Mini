<?php

declare(strict_types=1);

namespace PenBlog\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * JSON 请求体解析:PSR-7 创建器不解析 application/json,这里补上。
 */
final class BodyParserMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json') && $request->getParsedBody() === null) {
            $raw = (string) $request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $request = $request->withParsedBody($decoded);
                }
            }
        }
        return $handler->handle($request);
    }
}
