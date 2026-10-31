<?php

declare(strict_types=1);

namespace PenBlog\Http;

use PenBlog\Api\ApiException;
use PenBlog\Auth\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF 双提交(09 §4):仅当"不安全方法 + /api/ 或 /admin/ 前缀 + 带会话 Cookie
 * + 未带 Bearer"时要求 X-CSRF-Token 头(或 _csrf 表单字段)与 penblog_csrf Cookie 一致。
 * PAT 认证天然免疫 CSRF,登录端点豁免(尚无会话)。
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const EXEMPT = ['/api/v1/auth/login', '/admin/login'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        if (in_array($method, self::UNSAFE, true)
            && !in_array($path, self::EXEMPT, true)
            && (str_starts_with($path, '/api/') || str_starts_with($path, '/admin/'))
            && $request->getHeaderLine('Authorization') === ''
            && isset($request->getCookieParams()[SessionStore::COOKIE])) {
            $cookie = (string) ($request->getCookieParams()[SessionStore::CSRF_COOKIE] ?? '');
            $sent = $request->getHeaderLine('X-CSRF-Token');
            if ($sent === '') {
                $parsed = $request->getParsedBody();
                $sent = is_array($parsed) ? (string) ($parsed['_csrf'] ?? '') : '';
            }
            if ($cookie === '' || $sent === '' || !hash_equals($cookie, $sent)) {
                throw new ApiException(403, 'csrf_failed', 'CSRF 校验失败');
            }
        }

        return $handler->handle($request);
    }
}
