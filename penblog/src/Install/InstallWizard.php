<?php

declare(strict_types=1);

namespace PenBlog\Install;

use Nyholm\Psr7\Response;
use PenBlog\Auth\SessionStore;
use PenBlog\Auth\UserRepository;
use PenBlog\Kernel\Kernel;
use PenBlog\Theme\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Web 安装向导(10 §3):环境自检 + 一屏配置 + 执行完成页。
 * 仅在安装守卫模式(无 config)下由 Kernel 接管,复用 InstallService 同一内核入口。
 * 安全红线(10 §6):一次性 CSRF 双提交、POST 限流、config 落盘即注销 /install。
 */
final class InstallWizard
{
    public const CSRF_COOKIE = 'pb_install_csrf';

    private const RATE_LIMIT = 10;        // 每 IP 每小时 POST 次数(10 §6)
    private const RATE_WINDOW = 3600;
    private const ENV_CACHE_TTL = 60;     // 自检结果缓存 60s(10 §3)

    private string $codeRoot;

    public function __construct(
        private readonly string $rootDir,
        private readonly string $configPath,
    ) {
        $this->codeRoot = dirname(__DIR__, 2);
    }

    public static function forProject(): self
    {
        $root = dirname(__DIR__, 2);
        return new self($root, $root . '/config/config.php');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/install') {
            return $request->getMethod() === 'POST' ? $this->submit($request) : $this->form($request);
        }
        if (str_starts_with($path, '/assets/')) {
            $file = substr($path, 8);
            if (preg_match('/^[a-z0-9_-]+\.(?:css|js|svg|png|jpg|woff2)$/', $file) !== 1) {
                return new Response(404);
            }
            return $this->asset($file);
        }
        return new Response(302, ['Location' => '/install']);
    }

    // ---------------------------------------------------------------- 步骤 1+2:自检与配置表单

    /** @param array<string,string> $errors @param array<string,mixed> $old */
    private function form(
        ServerRequestInterface $request,
        array $errors = [],
        array $old = [],
        int $status = 200,
        ?string $setCookie = null,
    ): ResponseInterface {
        $csrf = (string) ($request->getCookieParams()[self::CSRF_COOKIE] ?? '');
        if (preg_match('/^[0-9a-f]{32}$/', $csrf) !== 1) {
            $csrf = SessionStore::csrfToken();
            $setCookie = sprintf(
                '%s=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=3600',
                self::CSRF_COOKIE,
                $csrf,
            );
        }
        $driver = (string) ($old['driver'] ?? 'sqlite');
        $rows = $this->cachedEnvCheck($driver);
        $html = View::render($this->codeRoot . '/themes/paper/templates/install.php', [
            'envRows' => $rows,
            'blocked' => array_any($rows, static fn (array $r): bool => $r['required'] && !$r['ok']),
            'csrf' => $csrf,
            'errors' => $errors,
            'old' => $old,
            'detectedUrl' => $this->detectedUrl($request),
            'version' => Kernel::VERSION,
        ]);
        $headers = ['Content-Type' => 'text/html; charset=utf-8'];
        if ($setCookie !== null) {
            $headers['Set-Cookie'] = $setCookie;
        }
        return new Response($status, $headers, $html);
    }

    /** @return list<array{item: string, ok: bool, required: bool, hint: string}> */
    private function cachedEnvCheck(string $driver): array
    {
        $file = $this->rootDir . '/storage/.install_env_' . ($driver === 'mysql' ? 'mysql' : 'sqlite') . '.json';
        $now = time();
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && ($data['ts'] ?? 0) > $now - self::ENV_CACHE_TTL && is_array($data['rows'] ?? null)) {
                /** @var list<array{item: string, ok: bool, required: bool, hint: string}> $rows */
                $rows = $data['rows'];
                return $rows;
            }
        }
        $rows = (new EnvironmentChecker())->check(['driver' => $driver], $this->rootDir);
        @mkdir(dirname($file), 0750, true);
        @file_put_contents($file, json_encode(['ts' => $now, 'rows' => $rows], JSON_UNESCAPED_UNICODE));
        return $rows;
    }

    // ---------------------------------------------------------------- 步骤 3:提交执行

    private function submit(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $ip = $request->getHeaderLine('X-Forwarded-For') ?: (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->rateAllowed(trim($ip) !== '' ? $ip : '0.0.0.0')) {
            return $this->form($request, ['#' => '安装请求过于频繁(每 IP 每小时 ' . self::RATE_LIMIT . ' 次),请稍后再试。'], $body, 429);
        }

        // 一次性 CSRF 双提交校验(10 §3)
        $cookie = (string) ($request->getCookieParams()[self::CSRF_COOKIE] ?? '');
        $sent = (string) ($body['_csrf'] ?? '');
        if ($cookie === '' || $sent === '' || !hash_equals($cookie, $sent)) {
            return $this->form($request, ['#' => 'CSRF 校验失败,请刷新页面后重新提交。'], $body, 403);
        }

        [$errors, $plan] = $this->validate($body);
        if ($errors !== []) {
            return $this->form($request, $errors, $body, 422);
        }

        try {
            $result = (new InstallService())->run($plan);
        } catch (\Throwable $e) {
            // 连接错误等原样展示(10 §3 步骤 1 要求)
            return $this->form($request, ['#' => '安装失败:' . $e->getMessage()], $body, 500);
        }

        // config 已落盘 → /install 整体注销;完成页仅本次展示密码
        $autoLogin = !array_key_exists('auto_login', $body) || !empty($body['auto_login']);
        $cookies = $autoLogin ? $this->autoLoginCookies($plan, $request) : [];
        $html = View::render($this->codeRoot . '/themes/paper/templates/install_complete.php', [
            'siteTitle' => $plan->siteTitle,
            'adminUser' => $plan->adminUser,
            'adminPass' => $result['adminPass'],
            'passGenerated' => $result['passGenerated'],
            'configPath' => $plan->configPath,
            'migrations' => $result['migrations'],
            'autoLogin' => $autoLogin,
            'version' => Kernel::VERSION,
        ]);
        $headers = ['Content-Type' => 'text/html; charset=utf-8'];
        if ($cookies !== []) {
            $headers['Set-Cookie'] = $cookies;
        }
        return new Response(200, $headers, $html);
    }

    /** @return array{0: array<string,string>, 1: InstallPlan} 服务端全量重校验(10 §3) */
    public function validate(array $body): array
    {
        $errors = [];

        $siteTitle = trim((string) ($body['site_title'] ?? ''));
        if ($siteTitle === '' || mb_strlen($siteTitle) > 120) {
            $errors['site_title'] = '站点标题必填(不超过 120 字)。';
        }

        $siteUrl = trim((string) ($body['site_url'] ?? ''));
        if (preg_match('#^https?://#i', $siteUrl) !== 1 || filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            $errors['site_url'] = '站点 URL 需为 http(s):// 开头的完整地址。';
        }
        $siteUrl = rtrim($siteUrl, '/');

        $driver = (string) ($body['driver'] ?? 'sqlite');
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            $errors['driver'] = '不支持的数据库类型。';
        }
        $dbConfig = ['driver' => $driver];
        if ($driver === 'mysql') {
            $host = trim((string) ($body['mysql_host'] ?? ''));
            $port = (int) ($body['mysql_port'] ?? 3306);
            $dbname = trim((string) ($body['mysql_database'] ?? ''));
            $dbUser = trim((string) ($body['mysql_username'] ?? ''));
            $dbPass = (string) ($body['mysql_password'] ?? '');
            if ($host === '' || mb_strlen($host) > 128) {
                $errors['mysql_host'] = '数据库主机必填。';
            }
            if ($port < 1 || $port > 65535) {
                $errors['mysql_port'] = '端口范围 1–65535。';
            }
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbname) !== 1) {
                $errors['mysql_database'] = '库名必填,仅限字母、数字、下划线(≤64)。';
            }
            if ($dbUser === '' || mb_strlen($dbUser) > 64) {
                $errors['mysql_username'] = '数据库用户名必填(≤64 字符)。';
            }
            $dbConfig = [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $dbname, 'username' => $dbUser, 'password' => $dbPass,
            ];
        } else {
            $dbConfig['path'] = $this->rootDir . '/storage/data.db';
        }

        $adminUser = trim((string) ($body['admin_user'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{3,32}$/', $adminUser) !== 1) {
            $errors['admin_user'] = '管理员用户名需为 3–32 位字母、数字、下划线或短横线。';
        }

        $adminEmail = trim((string) ($body['admin_email'] ?? ''));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors['admin_email'] = '邮箱格式不正确。';
        }

        $adminPass = (string) ($body['admin_pass'] ?? '');
        if ($adminPass !== '' && (mb_strlen($adminPass) < 8 || mb_strlen($adminPass) > 200)) {
            $errors['admin_pass'] = '密码需至少 8 位(留空则自动生成 16 位强密码)。';
        }

        $plan = new InstallPlan(
            dbConfig: $dbConfig,
            siteTitle: $siteTitle !== '' ? $siteTitle : 'My PenBlog',
            siteUrl: $siteUrl !== '' ? $siteUrl : 'http://localhost:8080',
            adminUser: $adminUser !== '' ? $adminUser : 'admin',
            adminEmail: $adminEmail !== '' ? $adminEmail : 'admin@example.com',
            adminPass: $adminPass !== '' ? $adminPass : null,
            devMode: !empty($body['dev_mode']),
            rootDir: $this->rootDir,
            configPath: $this->configPath,
        );
        return [$errors, $plan];
    }

    // ---------------------------------------------------------------- 助手

    /** @return list<string> 自动登录会话 Cookie(10 §3 完成页直达后台) */
    private function autoLoginCookies(InstallPlan $plan, ServerRequestInterface $request): array
    {
        try {
            $kernel = Kernel::boot($this->configPath);
            $admin = $kernel->container->get(UserRepository::class)->findByUsername($plan->adminUser);
            if ($admin === null) {
                return [];
            }
            /** @var SessionStore $sessions */
            $sessions = $kernel->container->get(SessionStore::class);
            $ip = $request->getHeaderLine('X-Forwarded-For') ?: (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $raw = $sessions->create($admin->id(), $ip !== '' ? $ip : null, $request->getHeaderLine('User-Agent') ?: null, false);
            $csrf = SessionStore::csrfToken();
            return [
                sprintf('%s=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=%d', SessionStore::COOKIE, $raw, SessionStore::TTL_SECONDS),
                sprintf('%s=%s; Path=/; SameSite=Lax; Max-Age=%d', SessionStore::CSRF_COOKIE, $csrf, SessionStore::TTL_SECONDS),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str((string) $request->getBody(), $form);
            return is_array($form) ? $form : [];
        }
        $decoded = json_decode((string) $request->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function rateAllowed(string $ip): bool
    {
        $file = $this->rootDir . '/storage/.install_attempts.json';
        $now = time();
        $data = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $hits = array_values(array_filter(
            (array) ($data[$ip] ?? []),
            static fn ($t): bool => is_int($t) && $t > $now - self::RATE_WINDOW,
        ));
        if (count($hits) >= self::RATE_LIMIT) {
            return false;
        }
        $hits[] = $now;
        $data[$ip] = $hits;
        @mkdir(dirname($file), 0750, true);
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    private function detectedUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $host = $uri->getHost() !== '' ? $uri->getHost() : 'localhost';
        $scheme = $request->getHeaderLine('X-Forwarded-Proto') ?: ($uri->getScheme() !== '' ? $uri->getScheme() : 'http');
        $port = $uri->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $net = $host . ($port !== null && $port !== $defaultPort ? ':' . $port : '');
        return $scheme . '://' . $net;
    }

    private function asset(string $file): ResponseInterface
    {
        $path = $this->codeRoot . '/themes/paper/assets/' . $file;
        if (!is_file($path)) {
            return new Response(404);
        }
        $type = match (true) {
            str_ends_with($file, '.css') => 'text/css',
            str_ends_with($file, '.js') => 'text/javascript',
            str_ends_with($file, '.svg') => 'image/svg+xml',
            str_ends_with($file, '.woff2') => 'font/woff2',
            default => 'image/png',
        };
        return new Response(200, [
            'Content-Type' => $type . '; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ], (string) file_get_contents($path));
    }
}
