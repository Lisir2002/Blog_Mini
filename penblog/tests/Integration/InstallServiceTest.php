<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Install\InstallPlan;
use PenBlog\Install\InstallService;
use PHPUnit\Framework\TestCase;

final class InstallServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/penblog-install-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/logs', 0750, true);
        mkdir($this->root . '/config', 0750, true);
        mkdir($this->root . '/themes', 0750, true);
        mkdir($this->root . '/plugins', 0750, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    public function testFullInstallWritesConfigAndSeeds(): void
    {
        $plan = new InstallPlan(
            dbConfig: ['driver' => 'sqlite', 'path' => $this->root . '/storage/data.db'],
            siteTitle: '测试站',
            siteUrl: 'http://example.test',
            adminUser: 'boss',
            adminEmail: 'boss@example.test',
            adminPass: null,
            rootDir: $this->root,
            configPath: $this->root . '/config/config.php',
        );
        $result = (new InstallService())->run($plan);

        // 随机口令 16 位 hex
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $result['adminPass']);
        self::assertTrue($result['passGenerated']);

        // config 落盘且含版本与 secret
        self::assertFileExists($plan->configPath);
        $config = require $plan->configPath;
        self::assertSame(\PenBlog\Kernel\Kernel::VERSION, $config['penblog_version']);
        self::assertNotEmpty($config['app']['secret_key']);
        self::assertSame('sqlite', $config['db']['driver']);

        // boot 后可用,种子齐全
        $kernel = \PenBlog\Kernel\Kernel::boot($plan->configPath);
        self::assertFalse($kernel->isInstalling());
        self::assertNotNull($kernel->container->get(\PenBlog\Auth\UserRepository::class)->findByUsername('boss'));
        self::assertNotNull($kernel->extensions()->find('AccessLevel', 'level-0'));
        self::assertNotNull($kernel->extensions()->find('Theme', 'paper'));
        self::assertSame('测试站', $kernel->options()->get('site.title'));
        self::assertSame('closed', $kernel->options()->get('auth.registration'));

        // Hello 文章已发布
        $stmt = $kernel->db()->pdo()->prepare("SELECT COUNT(*) AS c FROM posts WHERE status = 'published' AND deleted_at IS NULL");
        $stmt->execute();
        self::assertGreaterThanOrEqual(1, (int) $stmt->fetch()['c']);
    }

    public function testRefusesOverwriteWithoutForce(): void
    {
        $plan = InstallPlan::defaults(['driver' => 'sqlite', 'path' => $this->root . '/storage/data.db']);
        file_put_contents($plan->configPath, "<?php\n\nreturn [];");
        $this->expectException(\RuntimeException::class);
        (new InstallService())->run($plan);
    }

    public function testInstalledKernelServesHealthAnd404sInstall(): void
    {
        $plan = new InstallPlan(
            dbConfig: ['driver' => 'sqlite', 'path' => $this->root . '/storage/data.db'],
            adminPass: 'somepassword',
            rootDir: $this->root,
            configPath: $this->root . '/config/config.php',
        );
        (new InstallService())->run($plan);
        $kernel = \PenBlog\Kernel\Kernel::boot($plan->configPath);

        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        self::assertSame(200, $kernel->handle($factory->createServerRequest('GET', '/api/v1/health'))->getStatusCode());
        // 已安装则 /install 不再注册
        self::assertSame(404, $kernel->handle($factory->createServerRequest('GET', '/install'))->getStatusCode());
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
