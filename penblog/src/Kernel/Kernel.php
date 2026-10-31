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
    public const VERSION = '0.1.0-m1';

    public readonly Container $container;
    public readonly Router $router;
    public readonly EventBus $events;
    public readonly Config $config;

    /** @var list<\Psr\Http\Server\MiddlewareInterface> */
    private array $globalMiddleware = [];

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
            return $m;
        });

        $this->registerCoreRoutes();
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config, array $env = []): self
    {
        return new self(new Config($config, $env));
    }

    public static function boot(?string $configPath = null): self
    {
        $configPath ??= dirname(__DIR__, 2) . '/config/config.php';
        return new self(Config::fromFile($configPath, getenv() ?: []));
    }

    public function addMiddleware(\Psr\Http\Server\MiddlewareInterface $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());
            if ($match === null) {
                return $this->json(404, ['error' => ['code' => 'not_found', 'message' => 'Route not found']]);
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
        $this->router->get('/', fn (): ResponseInterface => $this->json(200, [
            'name' => 'PenBlog Engine',
            'version' => self::VERSION,
            'status' => 'ok',
        ]));
        $this->router->get('/api/v1/health', fn (): ResponseInterface => $this->json(200, [
            'status' => 'ok',
            'db' => $this->db()->name(),
            'time' => gmdate('c'),
        ]));
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
