<?php

declare(strict_types=1);

namespace PenBlog\Api;

use PenBlog\Auth\AccessLevel;
use PenBlog\Auth\Contracts\LoginThrottleHandler;
use PenBlog\Auth\Contracts\TwoFactorHandler;
use PenBlog\Auth\PasswordHasher;
use PenBlog\Auth\PermissionResolver;
use PenBlog\Auth\SessionStore;
use PenBlog\Auth\TokenStore;
use PenBlog\Auth\UserRepository;
use PenBlog\Http\AuthMiddleware;
use PenBlog\Kernel\Kernel;
use PenBlog\Kernel\OptionsStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 认证与账户 API(06 §3 / 09 §4):登录/登出/注册/本人资料/等级/头衔/PAT。
 */
final class AuthRoutes
{
    public static function register(Kernel $kernel): void
    {
        $c = $kernel->container;
        $router = $kernel->router;

        $users = static fn (): UserRepository => $c->get(UserRepository::class);
        $sessions = static fn (): SessionStore => $c->get(SessionStore::class);
        $resolver = static fn (): PermissionResolver => $c->get(PermissionResolver::class);

        // ---- 登录 ----
        $router->post('/api/v1/auth/login', static function (ServerRequestInterface $req) use ($c, $users, $sessions, $resolver): ResponseInterface {
            $body = Body::of($req);
            $identifier = trim((string) ($body['username'] ?? $body['email'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            $remember = (bool) ($body['remember'] ?? false);
            $ip = $req->getHeaderLine('X-Forwarded-For') ?: ($_SERVER['REMOTE_ADDR'] ?? null);
            $ip = is_string($ip) ? $ip : null;

            /** @var ?LoginThrottleHandler $throttle */
            $throttle = $c->has(LoginThrottleHandler::class) ? $c->get(LoginThrottleHandler::class) : null;
            if ($throttle !== null && !$throttle->check((string) $ip, $identifier)) {
                return Json::error(429, 'too_many_requests', '尝试过于频繁,请稍后再试');
            }

            $user = $identifier !== '' && $password !== '' ? $users()->findForLogin($identifier) : null;
            /** @var PasswordHasher $hasher */
            $hasher = $c->get(PasswordHasher::class);
            if ($user === null || $user->status() !== 'active' || !$hasher->verify($password, $users()->passwordHashFor($user->id()))) {
                $throttle?->recordFailure((string) $ip, $identifier);
                return Json::error(401, 'invalid_credentials', '用户名或密码错误');
            }
            $throttle?->clear((string) $ip, $identifier);

            /** @var ?TwoFactorHandler $twoFactor */
            $twoFactor = $c->has(TwoFactorHandler::class) ? $c->get(TwoFactorHandler::class) : null;
            if ($twoFactor !== null && $twoFactor->isEnrolled($user) && !$twoFactor->verify($user, isset($body['code']) ? (string) $body['code'] : null)) {
                return Json::error(401, 'two_factor_required', '需要二次验证码', headers: ['X-PenBlog-2FA' => 'required']);
            }

            $users()->rehashIfNeeded($user, $password);
            // 会话固定防护:登录即轮换(撤销全部旧会话)
            $sessions()->revokeAllForUser($user->id());
            $raw = $sessions()->create($user->id(), $ip, $req->getHeaderLine('User-Agent') ?: null, $remember);
            $csrf = SessionStore::csrfToken();

            $definition = $resolver()->levelDefinition($user->level());
            return Json::ok(200, [
                'user' => $user->selfDto() + ['levelTitle' => $definition->title(), 'levelColor' => $definition->color()],
            ], self::authCookies($raw, $csrf, $remember));
        });

        // ---- 登出 ----
        $router->post('/api/v1/auth/logout', static function (ServerRequestInterface $req) use ($sessions): ResponseInterface {
            $raw = (string) ($req->getCookieParams()[SessionStore::COOKIE] ?? '');
            if ($raw !== '') {
                $sessions()->revoke($raw);
            }
            return Json::ok(200, ['ok' => true], [
                'Set-Cookie' => [self::expiredCookie(SessionStore::COOKIE), self::expiredCookie(SessionStore::CSRF_COOKIE)],
            ]);
        });

        // ---- CSRF token ----
        $router->get('/api/v1/auth/csrf', static function (): ResponseInterface {
            $csrf = SessionStore::csrfToken();
            return Json::ok(200, ['token' => $csrf], [
                'Set-Cookie' => sprintf('%s=%s; Path=/; SameSite=Lax; Max-Age=%d', SessionStore::CSRF_COOKIE, $csrf, SessionStore::REMEMBER_TTL_SECONDS),
            ]);
        });

        // ---- 注册 ----
        $router->post('/api/v1/auth/register', static function (ServerRequestInterface $req) use ($c, $users): ResponseInterface {
            $options = $c->get(OptionsStore::class);
            if ((string) $options->get('auth.registration', 'closed') !== 'open') {
                throw new ApiException(403, 'registration_closed', '站点已关闭注册');
            }
            $body = Body::of($req);
            $user = $users()->create(
                trim((string) ($body['username'] ?? '')),
                trim((string) ($body['email'] ?? '')),
                (string) ($body['password'] ?? ''),
                3,
            );
            return Json::ok(201, ['user' => $user->publicDto() + ['username' => $user->username()]]);
        });

        // ---- 本人资料 ----
        $router->get('/api/v1/user/me', static function (ServerRequestInterface $req) use ($resolver): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            $def = $resolver()->levelDefinition($gate->user->level());
            return Json::ok(200, ['user' => $gate->user->selfDto() + [
                'levelTitle' => $def->title(),
                'levelColor' => $def->color(),
                'permissions' => $gate->permissions(),
            ]]);
        });

        $router->put('/api/v1/user/me', static function (ServerRequestInterface $req) use ($c, $users): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            $body = Body::of($req);
            $changes = [];
            foreach (['displayName', 'avatar', 'bio', 'homepage'] as $k) {
                if (array_key_exists($k, $body)) {
                    $changes[$k] = $body[$k] === null ? null : (string) $body[$k];
                }
            }
            if (isset($body['email']) && trim((string) $body['email']) !== '' && trim((string) $body['email']) !== $gate->user->email()) {
                $newEmail = trim((string) $body['email']);
                if ($c->has(\PenBlog\Auth\Contracts\EmailChangeVerifier::class)) {
                    $verifier = $c->get(\PenBlog\Auth\Contracts\EmailChangeVerifier::class);
                    $verifier->request($gate->user, $newEmail);
                    $changes['email'] = null; // 未验证不落库
                    unset($changes['email']);
                    $updated = $gate->user;
                    return Json::ok(200, ['user' => $updated->selfDto(), 'email_verification_sent' => $newEmail]);
                }
                $changes['email'] = $newEmail;
            }
            if (isset($body['password'])) {
                $old = (string) ($body['oldPassword'] ?? '');
                /** @var PasswordHasher $hasher */
                $hasher = $c->get(PasswordHasher::class);
                if (!$hasher->verify($old, $users()->passwordHashFor($gate->user->id()))) {
                    throw new ValidationException([['field' => 'oldPassword', 'reason' => 'invalid']], '原密码错误');
                }
                $changes['passwordPlain'] = (string) $body['password'];
            }
            $updated = $changes === [] ? $gate->user : $users()->update($gate->user, $changes);
            return Json::ok(200, ['user' => $updated->selfDto()]);
        });

        // ---- 等级列表 ----
        $router->get('/api/v1/access-levels', static function (ServerRequestInterface $req) use ($resolver): ResponseInterface {
            $gate = AuthMiddleware::gate($req);
            $items = [];
            foreach ([0, 1, 2, 3] as $level) {
                $def = $resolver()->levelDefinition($level);
                $row = ['level' => $def->level(), 'title' => $def->title(), 'color' => $def->color()];
                if ($gate->allows('system:manage')) {
                    $row['permissions'] = $def->permissions();
                }
                $items[] = $row;
            }
            return Json::ok(200, ['items' => $items]);
        });

        // ---- 管理员调级 ----
        $router->put('/api/v1/users/{name}/level', static function (ServerRequestInterface $req) use ($c, $users): ResponseInterface {
            AuthMiddleware::gate($req)->require('system:manage');
            $name = Body::routeParams($req)['name'] ?? '';
            $level = (int) (Body::of($req)['level'] ?? -1);
            $user = $users()->setLevel((string) $name, $level);
            return Json::ok(200, ['user' => $user->publicDto() + ['username' => $user->username()]]);
        });

        // ---- PAT ----
        $router->post('/api/v1/tokens', static function (ServerRequestInterface $req) use ($c): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            $body = Body::of($req);
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::required('name');
            }
            $scopes = isset($body['scopes']) && is_array($body['scopes']) ? array_values(array_map('strval', $body['scopes'])) : null;
            $expiresAt = isset($body['expiresAt']) ? (string) $body['expiresAt'] : null;
            /** @var TokenStore $tokens */
            $tokens = $c->get(TokenStore::class);
            [$raw, $id] = $tokens->issue($gate->user->id(), $name, $scopes, $expiresAt);
            return Json::ok(201, ['token' => ['id' => $id, 'name' => $name, 'raw' => $raw, 'scopes' => $scopes, 'expiresAt' => $expiresAt]],);
        });

        $router->get('/api/v1/tokens', static function (ServerRequestInterface $req) use ($c): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            /** @var TokenStore $tokens */
            $tokens = $c->get(TokenStore::class);
            return Json::ok(200, ['items' => $tokens->listForUser($gate->user->id())]);
        });

        $router->delete('/api/v1/tokens/{id}', static function (ServerRequestInterface $req) use ($c): ResponseInterface {
            $gate = AuthMiddleware::gate($req)->requireAuth();
            /** @var TokenStore $tokens */
            $tokens = $c->get(TokenStore::class);
            $id = (int) (Body::routeParams($req)['id'] ?? 0);
            if (!$tokens->revoke($id, $gate->user->id())) {
                throw ApiException::notFound('Token');
            }
            return Json::ok(200, ['ok' => true]);
        });
    }

    /** 登录成功:会话 Cookie(HttpOnly)+ CSRF 双提交 Cookie。 */
    private static function authCookies(string $raw, string $csrf, bool $remember): array
    {
        $ttl = $remember ? SessionStore::REMEMBER_TTL_SECONDS : SessionStore::TTL_SECONDS;
        $session = sprintf(
            '%s=%s; Path=/; HttpOnly; SameSite=Lax%s',
            SessionStore::COOKIE,
            $raw,
            $remember ? '; Max-Age=' . $ttl : '',
        );
        $csrfCookie = sprintf('%s=%s; Path=/; SameSite=Lax; Max-Age=%d', SessionStore::CSRF_COOKIE, $csrf, $ttl);
        return ['Set-Cookie' => [$session, $csrfCookie]];
    }

    private static function expiredCookie(string $name): string
    {
        return sprintf('%s=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0', $name);
    }
}
