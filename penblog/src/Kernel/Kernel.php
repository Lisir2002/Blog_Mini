<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PenBlog\Http\Pipeline;
use PenBlog\Repository\DriverFactory;
use PenBlog\Repository\Migrator;
use PenBlog\Repository\SqlDriver;
use PenBlog\Support\FileLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * 内核引导(02 §7 五步):loadConfig → boot → register → dispatch → render。
 */
final class Kernel
{
    public const VERSION = '0.2.0-m2';

    public readonly Container $container;
    public readonly Router $router;
    public readonly EventBus $events;
    public readonly Config $config;

    /** @var list<\Psr\Http\Server\MiddlewareInterface> */
    private array $globalMiddleware = [];

    private bool $installing = false;

    private function __construct(Config $config)
    {
        $this->config = $config;
        $this->container = new Container();
        $this->events = new EventBus();
        $this->router = new Router();

        $container = $this->container;
        $container->instance(Config::class, $config);
        $container->instance(EventBus::class, $this->events);
        $container->instance(Router::class, $this->router);
        $container->instance(Kernel::class, $this);

        $container->singleton(SqlDriver::class, static fn (): SqlDriver => DriverFactory::make((array) $config->get('db', [])));
        $container->singleton(LoggerInterface::class, static fn (Container $c): LoggerInterface => new FileLogger(
            (string) $c->get(Config::class)->get('paths.logs', 'storage/logs/app.log'),
            (string) $c->get(Config::class)->get('app.debug', false) ? 'debug' : 'warning',
        ));
        $container->singleton(OptionsStore::class, static fn (Container $c): OptionsStore => new OptionsStore($c->get(SqlDriver::class)));
        $container->singleton(SecretStore::class, static fn (Container $c): SecretStore => new SecretStore(
            $c->get(SqlDriver::class),
            ($k = $c->get(Config::class)->get('app.secret_key')) !== null ? (string) $k : null,
        ));
        $container->singleton(ExtensionRegistry::class, static fn (Container $c): ExtensionRegistry => new ExtensionRegistry($c->get(SqlDriver::class)));
        $container->singleton(Migrator::class, static function (Container $c): Migrator {
            $m = new Migrator($c->get(SqlDriver::class));
            $m->register('0001-initial-schema', new \PenBlog\Database\Migrations\InitialSchema());
            $m->register('0002-auth-schema', new \PenBlog\Database\Migrations\AuthSchema());
            $m->register('0003-content-schema', new \PenBlog\Database\Migrations\ContentSchema());
            return $m;
        });

        // ---- 认证子系统(09)----
        $container->singleton(\PenBlog\Auth\PasswordHasher::class, static fn (): \PenBlog\Auth\PasswordHasher => new \PenBlog\Auth\PasswordHasher());
        $container->singleton(\PenBlog\Auth\SessionStore::class, static fn (Container $c): \PenBlog\Auth\SessionStore => new \PenBlog\Auth\SessionStore($c->get(SqlDriver::class)));
        $container->singleton(\PenBlog\Auth\TokenStore::class, static fn (Container $c): \PenBlog\Auth\TokenStore => new \PenBlog\Auth\TokenStore(
            $c->get(SqlDriver::class),
            $c->get(\PenBlog\Auth\PasswordHasher::class),
        ));
        $container->singleton(\PenBlog\Auth\UserRepository::class, static fn (Container $c): \PenBlog\Auth\UserRepository => new \PenBlog\Auth\UserRepository(
            $c->get(SqlDriver::class),
            $c->get(\PenBlog\Auth\PasswordHasher::class),
        ));
        $container->singleton(\PenBlog\Auth\PermissionResolver::class, static fn (Container $c): \PenBlog\Auth\PermissionResolver => new \PenBlog\Auth\PermissionResolver($c->get(ExtensionRegistry::class)));

        $this->extensions()->registerType(\PenBlog\Auth\User::KIND, \PenBlog\Auth\User::class);
        $this->extensions()->registerType(\PenBlog\Auth\AccessLevel::KIND, \PenBlog\Auth\AccessLevel::class);
        $this->extensions()->registerType(\PenBlog\Content\Post::KIND, \PenBlog\Content\Post::class);
        $this->extensions()->registerType(\PenBlog\Content\Page::KIND, \PenBlog\Content\Page::class);

        // ---- 内容子系统(07)----
        $container->singleton(\PenBlog\Content\PostRepository::class, static fn (Container $c): \PenBlog\Content\PostRepository => new \PenBlog\Content\PostRepository(
            $c->get(SqlDriver::class),
            $c->get(ExtensionRegistry::class),
        ));

        $this->addMiddleware(new \PenBlog\Http\BodyParserMiddleware());
        $this->addMiddleware(new \PenBlog\Http\AuthMiddleware($this->container));
        $this->addMiddleware(new \PenBlog\Http\CsrfMiddleware());

        \PenBlog\Api\AuthRoutes::register($this);
        \PenBlog\Api\ContentRoutes::register($this);
        $this->registerCoreRoutes();
        \PenBlog\Theme\ThemeRoutes::register($this);
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config, array $env = []): self
    {
        return new self(new Config($config, $env));
    }

    public static function boot(?string $configPath = null): self
    {
        $configPath ??= dirname(__DIR__, 2) . '/config/config.php';
        try {
            return new self(Config::fromFile($configPath, getenv() ?: []));
        } catch (NotInstalledException) {
            // 未安装:进入安装守卫模式(10 §6),不触达数据库
            $kernel = new self(new Config(['app' => ['debug' => false]], getenv() ?: []));
            $kernel->installing = true;
            return $kernel;
        }
    }

    public function isInstalling(): bool
    {
        return $this->installing;
    }

    public function addMiddleware(\Psr\Http\Server\MiddlewareInterface $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if ($this->installing) {
                return $this->installingResponse($request);
            }
            $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());
            if ($match === null) {
                if (str_starts_with($request->getUri()->getPath(), '/api/')) {
                    return $this->json(404, ['error' => ['code' => 'not_found', 'message' => 'Route not found']]);
                }
                return \PenBlog\Theme\ThemeRoutes::notFound($this, $request);
            }
            if ($match->isMethodNotAllowed()) {
                return $this->json(405, ['error' => ['code' => 'method_not_allowed', 'message' => '']], ['Allow' => implode(', ', $match->allowedMethods)]);
            }
            $request = $request->withAttribute('route_params', $match->params);
            $pipeline = new Pipeline(
                [...$this->globalMiddleware, ...$match->middleware],
                fn (ServerRequestInterface $r): ResponseInterface => ($match->handler)($r),
            );
            return $pipeline->handle($request);
        } catch (NotInstalledException $e) {
            return $this->json(503, ['error' => ['code' => 'not_installed', 'message' => $e->getMessage()]]);
        } catch (\PenBlog\Api\ApiException $e) {
            return \PenBlog\Api\Json::error($e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers);
        } catch (\Throwable $e) {
            try {
                $this->logger()->error($e->getMessage(), ['exception' => get_class($e)]);
            } catch (\Throwable) {
            }
            $debug = (bool) $this->config->get('app.debug', false);
            return $this->json(500, ['error' => [
                'code' => 'internal',
                'message' => $debug ? $e->getMessage() : 'Internal error',
                'requestId' => bin2hex(random_bytes(6)),
            ]]);
        }
    }

    /** 主题/API 共用的查询门面(02 §5)。 */
    public function widget(string $kind, array $params = []): Page
    {
        return $this->container->get(ExtensionRegistry::class)->query($kind, ListQuery::fromParams($params));
    }

    public function options(): OptionsStore
    {
        return $this->container->get(OptionsStore::class);
    }

    public function secrets(): SecretStore
    {
        return $this->container->get(SecretStore::class);
    }

    public function extensions(): ExtensionRegistry
    {
        return $this->container->get(ExtensionRegistry::class);
    }

    public function logger(): LoggerInterface
    {
        return $this->container->get(LoggerInterface::class);
    }

    public function db(): SqlDriver
    {
        return $this->container->get(SqlDriver::class);
    }

    private function registerCoreRoutes(): void
    {
        // 引擎信息并入健康端点;GET / 由主题渲染(ThemeRoutes)
        $this->router->get('/api/v1/health', fn (): ResponseInterface => $this->json(200, [
            'name' => 'PenBlog Engine',
            'version' => self::VERSION,
            'status' => 'ok',
            'db' => $this->db()->name(),
            'time' => gmdate('c'),
        ]));
    }

    /** 主题页响应助手(08)。 */
    public function html(string $body, int $status = 200, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'] + $headers, $body);
    }

    /** 安装守卫(10 §6):/install 自检占位页,API 503,其余 302。 */
    private function installingResponse(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/')) {
            return $this->json(503, ['error' => ['code' => 'not_installed', 'message' => 'PenBlog 尚未安装']]);
        }
        if ($path === '/install' && $request->getMethod() === 'GET') {
            $rows = (new \PenBlog\Install\EnvironmentChecker())->check(['driver' => 'sqlite'], dirname(__DIR__, 2));
            $items = '';
            foreach ($rows as $row) {
                $mark = $row['ok'] ? '✓' : ($row['required'] ? '✗' : '!');
                $color = $row['ok'] ? '#15803D' : ($row['required'] ? '#B91C1C' : '#B45309');
                $items .= sprintf(
                    '<li style="color:%s">%s %s —— %s%s</li>',
                    $color, $mark, htmlspecialchars($row['item'], ENT_QUOTES),
                    htmlspecialchars($row['hint'], ENT_QUOTES),
                    $row['required'] ? '' : '(建议)',
                );
            }
            $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>安装 PenBlog</title></head><body style="font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px">'
                . '<h1>PenBlog 尚未安装</h1><p>环境自检:</p><ul style="line-height:1.9">' . $items . '</ul>'
                . '<p>Web 安装向导将在 M3 提供;当前可执行 <code>php bin/install.php</code> 完成安装。</p>'
                . '</body></html>';
            return $this->html($html);
        }
        return new Response(302, ['Location' => '/install']);
    }

    /** @param array<string, mixed> $data */
    private function json(int $status, array $data, array $headers = []): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }
}
