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
    public const VERSION = '0.6.0-m8';

    public readonly Container $container;
    public readonly Router $router;
    public readonly EventBus $events;
    public readonly Config $config;

    /** @var list<\Psr\Http\Server\MiddlewareInterface> */
    private array $globalMiddleware = [];

    private bool $installing = false;

    private ?string $configPath = null;

    private bool $pluginsBooted = false;

    private ?\PenBlog\Install\InstallWizard $wizard = null;

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
            $m->register('0004-notification-schema', new \PenBlog\Database\Migrations\NotificationSchema());
            $m->register('0005-webhook-schema', new \PenBlog\Database\Migrations\WebhookSchema());
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
            $c->get(EventBus::class),
        ));

        // ---- 通知子系统(05 五层管线)----
        $container->singleton(\PenBlog\Notification\PreferenceRepository::class, static fn (Container $c): \PenBlog\Notification\PreferenceRepository => new \PenBlog\Notification\PreferenceRepository($c->get(SqlDriver::class)));
        $container->singleton(\PenBlog\Notification\NotificationRepository::class, static fn (Container $c): \PenBlog\Notification\NotificationRepository => new \PenBlog\Notification\NotificationRepository($c->get(SqlDriver::class)));
        $container->singleton(\PenBlog\Notification\NotificationTemplates::class, static fn (): \PenBlog\Notification\NotificationTemplates => new \PenBlog\Notification\NotificationTemplates());
        $container->singleton(\PenBlog\Notification\Mailer::class, static fn (Container $c): \PenBlog\Notification\Mailer => \PenBlog\Notification\Mailer::fromConfig($c->get(Config::class)));
        $container->singleton(\PenBlog\Notification\NotificationPipeline::class, static fn (Container $c): \PenBlog\Notification\NotificationPipeline => new \PenBlog\Notification\NotificationPipeline(
            $c->get(SqlDriver::class),
            $c->get(\PenBlog\Auth\UserRepository::class),
            $c->get(\PenBlog\Notification\PreferenceRepository::class),
            $c->get(\PenBlog\Notification\NotificationRepository::class),
            $c->get(\PenBlog\Notification\NotificationTemplates::class),
            [
                'email' => new \PenBlog\Notification\Channels\EmailChannel($c->get(\PenBlog\Notification\Mailer::class)),
                'webhook' => new \PenBlog\Notification\Channels\WebhookChannel($c->get(OptionsStore::class)),
            ],
        ));

        // ---- Webhook 出站(06 §8)----
        $container->singleton(\PenBlog\Webhook\WebhookRepository::class, static fn (Container $c): \PenBlog\Webhook\WebhookRepository => new \PenBlog\Webhook\WebhookRepository($c->get(SqlDriver::class)));
        $container->singleton(\PenBlog\Webhook\WebhookDispatcher::class, static fn (Container $c): \PenBlog\Webhook\WebhookDispatcher => new \PenBlog\Webhook\WebhookDispatcher(
            $c->get(\PenBlog\Webhook\WebhookRepository::class),
            $c->get(LoggerInterface::class),
        ));

        // ---- 插件系统(04)----
        $container->singleton(\PenBlog\Plugin\PluginManager::class, static fn (Container $c): \PenBlog\Plugin\PluginManager => new \PenBlog\Plugin\PluginManager(
            $c->get(Kernel::class),
            (string) $c->get(Config::class)->get('paths.plugins', dirname(__DIR__, 2) . '/plugins'),
        ));

        // ---- 主题系统(03)----
        $container->singleton(\PenBlog\Theme\ThemeManager::class, static function (Container $c): \PenBlog\Theme\ThemeManager {
            $bundled = dirname(__DIR__, 2) . '/themes';
            $dir = (string) $c->get(Config::class)->get('paths.themes', $bundled);
            if (!is_dir($dir)) {
                $dir = $bundled; // 配置指向缺失目录:回退发行内置,保证前台不白屏
            }
            return new \PenBlog\Theme\ThemeManager($c->get(Kernel::class), $dir);
        });

        $this->addMiddleware(new \PenBlog\Http\BodyParserMiddleware());
        $this->addMiddleware(new \PenBlog\Http\AuthMiddleware($this->container));
        $this->addMiddleware(new \PenBlog\Http\CsrfMiddleware());

        \PenBlog\Api\AuthRoutes::register($this);
        \PenBlog\Api\ContentRoutes::register($this);
        \PenBlog\Api\NotificationRoutes::register($this);
        \PenBlog\Api\WebhookRoutes::register($this);
        $this->registerCoreRoutes();
        \PenBlog\Theme\ThemeRoutes::register($this);
        \PenBlog\Notification\NotificationService::register($this);
        \PenBlog\Webhook\WebhookDispatcher::register($this);
        \PenBlog\Api\OpenApiRoutes::register($this);
        \PenBlog\Plugin\PluginRoutes::register($this);
        \PenBlog\Theme\ThemeAdminRoutes::register($this);
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
            $kernel = new self(Config::fromFile($configPath, getenv() ?: []));
            $kernel->configPath = $configPath;
            return $kernel;
        } catch (NotInstalledException) {
            // 未安装:进入安装守卫模式(10 §6),不触达数据库
            $kernel = new self(new Config(['app' => ['debug' => false]], getenv() ?: []));
            $kernel->installing = true;
            return $kernel;
        }
    }

    /** 引导所用 config 文件路径(fromConfig 组装时为 null)。 */
    public function configPath(): ?string
    {
        return $this->configPath;
    }

    /** 升级提示(10 §5):config 记录的版本与代码版本不一致。 */
    public function updateAvailable(): ?array
    {
        $recorded = (string) $this->config->get('penblog_version', '');
        if ($recorded !== '' && $recorded !== self::VERSION) {
            return ['from' => $recorded, 'to' => self::VERSION];
        }
        return null;
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
            if (!$this->pluginsBooted) {
                $this->pluginsBooted = true;
                $this->plugins()->boot();
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

    public function plugins(): \PenBlog\Plugin\PluginManager
    {
        return $this->container->get(\PenBlog\Plugin\PluginManager::class);
    }

    public function theme(): \PenBlog\Theme\ThemeManager
    {
        return $this->container->get(\PenBlog\Theme\ThemeManager::class);
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

    /** 安装守卫(10 §6):API 503;/install 与静态资源交给 Web 向导,其余 302。 */
    private function installingResponse(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/')) {
            return $this->json(503, ['error' => ['code' => 'not_installed', 'message' => 'PenBlog 尚未安装']]);
        }
        $this->wizard ??= \PenBlog\Install\InstallWizard::forProject();
        return $this->wizard->handle($request);
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
