<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PenBlog\Auth\SessionStore;
use PenBlog\Install\InstallWizard;
use PenBlog\Kernel\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Web 安装向导(10 §3):守卫路由、CSRF、校验、限流、全流程安装 + 自动登录。
 */
final class InstallWizardTest extends TestCase
{
    private string $root;
    private InstallWizard $wizard;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/penblog-wizard-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/logs', 0750, true);
        mkdir($this->root . '/config', 0750, true);
        $this->wizard = new InstallWizard($this->root, $this->root . '/config/config.php');
        $this->csrf = bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    private function request(string $method, string $path, ?array $body = null, array $cookies = []): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $req = $factory->createServerRequest($method, 'http://localhost:8080' . $path);
        if ($body !== null) {
            $req = $req->withParsedBody($body);
        }
        return $req->withCookieParams($cookies);
    }

    public function testGuardRoutingAndWizardPage(): void
    {
        // 安装守卫:GET /install 渲染向导;其余 302;API 503
        $resp = $this->wizard->handle($this->request('GET', '/install'));
        self::assertSame(200, $resp->getStatusCode());
        $html = (string) $resp->getBody();
        self::assertStringContainsString('PenBlog 安装向导', $html);
        self::assertStringContainsString('环境自检', $html);
        self::assertStringContainsString('name="site_title"', $html);
        self::assertStringContainsString('pb_install_csrf=', $resp->getHeaderLine('Set-Cookie'));

        self::assertSame(302, $this->wizard->handle($this->request('GET', '/'))->getStatusCode());
        self::assertSame(200, $this->wizard->handle($this->request('GET', '/assets/paper.css'))->getStatusCode());
        self::assertSame(404, $this->wizard->handle($this->request('GET', '/assets/x.php'))->getStatusCode());

        // Kernel 级守卫(真实项目根,只读 GET)
        $kernel = Kernel::boot($this->root . '/config/nope.php');
        self::assertTrue($kernel->isInstalling());
        self::assertSame(200, $kernel->handle($this->request('GET', '/install'))->getStatusCode());
        self::assertSame(503, $kernel->handle($this->request('GET', '/api/v1/health'))->getStatusCode());
    }

    public function testPostRejectsMissingOrWrongCsrf(): void
    {
        $resp = $this->wizard->handle($this->request('POST', '/install', ['site_title' => 'x']));
        self::assertSame(403, $resp->getStatusCode());
        self::assertFileDoesNotExist($this->root . '/config/config.php');

        $resp = $this->wizard->handle($this->request(
            'POST',
            '/install',
            ['_csrf' => str_repeat('0', 32)],
            [InstallWizard::CSRF_COOKIE => $this->csrf],
        ));
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testValidationErrorsDoNotWriteAnything(): void
    {
        $resp = $this->wizard->handle($this->request('POST', '/install', [
            '_csrf' => $this->csrf,
            'site_title' => '',
            'site_url' => 'notaurl',
            'admin_user' => 'a!',
            'admin_email' => 'bad',
            'admin_pass' => 'short',
        ], [InstallWizard::CSRF_COOKIE => $this->csrf]));
        self::assertSame(422, $resp->getStatusCode());
        $html = (string) $resp->getBody();
        self::assertStringContainsString('站点标题必填', $html);
        self::assertStringContainsString('邮箱格式不正确', $html);
        self::assertStringContainsString('密码需至少 8 位', $html);
        self::assertFileDoesNotExist($this->root . '/config/config.php');
    }

    public function testMysqlValidationRequiresCredentials(): void
    {
        [$errors, $plan] = $this->wizard->validate([
            'site_title' => 'T', 'site_url' => 'http://t.test', 'driver' => 'mysql',
            'admin_user' => 'admin', 'admin_email' => 'a@b.test',
        ]);
        self::assertArrayHasKey('mysql_database', $errors);
        self::assertArrayHasKey('mysql_username', $errors);
        self::assertSame('mysql', $plan->dbConfig['driver']);
    }

    public function testRateLimitTenPerHour(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $resp = $this->wizard->handle($this->request('POST', '/install', ['_csrf' => 'bad']));
            self::assertSame(403, $resp->getStatusCode(), "attempt {$i}");
        }
        $resp = $this->wizard->handle($this->request('POST', '/install', ['_csrf' => 'bad']));
        self::assertSame(429, $resp->getStatusCode());
        self::assertStringContainsString('过于频繁', (string) $resp->getBody());
    }

    public function testFullWebInstallWithAutoLogin(): void
    {
        $resp = $this->wizard->handle($this->request('POST', '/install', [
            '_csrf' => $this->csrf,
            'site_title' => '向导站',
            'site_url' => 'http://wizard.test',
            'driver' => 'sqlite',
            'admin_user' => 'boss',
            'admin_email' => 'boss@example.test',
            'admin_pass' => '',          // 留空 → 自动生成
            'auto_login' => '1',
        ], [InstallWizard::CSRF_COOKIE => $this->csrf]));

        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $html = (string) $resp->getBody();
        self::assertStringContainsString('安装完成', $html);
        self::assertStringContainsString('进入后台', $html);
        self::assertMatchesRegularExpression('/<code id="done-pass">([0-9a-f]{16})<\/code>/', $html);

        // config 落盘 → 退出 installing,/install 注销
        $kernel = Kernel::boot($this->root . '/config/config.php');
        self::assertFalse($kernel->isInstalling());
        self::assertSame(404, $kernel->handle($this->request('GET', '/install'))->getStatusCode());

        // 自动登录会话直达后台仪表盘
        $cookies = [];
        foreach ($resp->getHeader('Set-Cookie') as $line) {
            $pair = explode(';', $line, 2)[0];
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $cookies[trim($k)] = trim($v);
            }
        }
        self::assertArrayHasKey(SessionStore::COOKIE, $cookies);
        $dash = $kernel->handle($this->request('GET', '/admin/dashboard', null, $cookies));
        self::assertSame(200, $dash->getStatusCode());
        self::assertStringContainsString('内容 · 文章', (string) $dash->getBody());
    }

    public function testInstalledKernelNoLongerGuards(): void
    {
        $kernel = Kernel::fromConfig([
            'app' => ['debug' => true, 'url' => 'http://localhost', 'secret_key' => str_repeat('s', 32)],
            'db' => ['driver' => 'sqlite', 'path' => ':memory:'],
            'paths' => ['logs' => sys_get_temp_dir() . '/penblog-wizard.log'],
        ]);
        self::assertFalse($kernel->isInstalling());
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
