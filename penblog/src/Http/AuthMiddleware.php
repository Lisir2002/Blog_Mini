<?php

declare(strict_types=1);

namespace PenBlog\Http;

use PenBlog\Auth\Gate;
use PenBlog\Auth\SessionStore;
use PenBlog\Auth\TokenStore;
use PenBlog\Auth\UserRepository;
use PenBlog\Auth\PermissionResolver;
use PenBlog\Kernel\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 认证解析(06 §2 / 09 §4):顺序 Session Cookie → Bearer PAT → 匿名;
 * 结果以 request attr 'gate' 传递,路由层用 gate() 助手读取。
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const ATTR = 'gate';

    public function __construct(private readonly Container $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $gate = $this->resolve($request);
        if ($gate->isAuthenticated()) {
            /** @var UserRepository $users */
            $users = $this->container->get(UserRepository::class);
            $users->touchLastActive($gate->user->id());
        }
        return $handler->handle($request->withAttribute(self::ATTR, $gate));
    }

    /** 供路由/主题复用:从请求取 Gate,无 attr 则匿名。 */
    public static function gate(ServerRequestInterface $request): Gate
    {
        $gate = $request->getAttribute(self::ATTR);
        return $gate instanceof Gate ? $gate : Gate::anonymous();
    }

    private function resolve(ServerRequestInterface $request): Gate
    {
        /** @var PermissionResolver $resolver */
        $resolver = $this->container->get(PermissionResolver::class);
        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);

        // 1) Session Cookie
        $cookies = $request->getCookieParams();
        $raw = isset($cookies[SessionStore::COOKIE]) ? (string) $cookies[SessionStore::COOKIE] : '';
        if ($raw !== '') {
            /** @var SessionStore $sessions */
            $sessions = $this->container->get(SessionStore::class);
            $row = $sessions->validate($raw);
            if ($row !== null) {
                $user = $users->findById((int) $row['user_id']);
                if ($user !== null) {
                    return $resolver->gate($user);
                }
            }
        }

        // 2) Bearer PAT
        $auth = $request->getHeaderLine('Authorization');
        if (str_starts_with($auth, 'Bearer ')) {
            /** @var TokenStore $tokens */
            $tokens = $this->container->get(TokenStore::class);
            $row = $tokens->verify(substr($auth, 7));
            if ($row !== null) {
                $user = $users->findById((int) $row['user_id']);
                if ($user !== null) {
                    return $resolver->gate($user, $row['scopes']);
                }
            }
        }

        // 3) 匿名
        return Gate::anonymous();
    }
}
