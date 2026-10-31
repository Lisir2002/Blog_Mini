<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Auth\AccessLevel;
use PenBlog\Auth\UserRepository;
use PenBlog\Install\UpgradeService;
use PenBlog\Kernel\Kernel;
use PenBlog\Repository\Migration;
use PenBlog\Repository\Migrator;
use PenBlog\Repository\Schema;
use PHPUnit\Framework\TestCase;

/**
 * 升级与修复(10 §5):版本比对、自动备份、失败回滚、config 回写、后台横幅、CLI。
 * 使用真实临时 SQLite 文件库 + config 文件(boot 通道)。
 */
final class UpgradeServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->rmrf($dir);
        }
        $this->tmpDirs = [];
    }

    public function testPlanDetectsVersionDrift(): void
    {
        [$kernel] = $this->bootInstalled('0.1.0-test');
        $plan = (new UpgradeService())->plan($kernel);
        self::assertSame('0.1.0-test', $plan['from']);
        self::assertSame(Kernel::VERSION, $plan['to']);
        self::assertSame([], $plan['pending']);
        self::assertFalse($plan['upToDate']);
        self::assertNotNull($kernel->updateAvailable());
    }

    public function testUpgradeBacksUpAppliesAndRewritesConfig(): void
    {
        [$kernel, $dir] = $this->bootInstalled('0.1.0-test');
        $kernel->container->get(Migrator::class)->register('9999-demo', new class implements Migration {
            public function up(Schema $schema): void
            {
                $schema->raw('CREATE TABLE upgrade_demo (id INTEGER PRIMARY KEY)');
            }
        });

        $service = new UpgradeService();
        self::assertSame(['9999-demo'], $service->plan($kernel)['pending']);

        $result = $service->run($kernel);
        self::assertSame(Kernel::VERSION, $result['to']);
        self::assertSame(['9999-demo'], $result['migrations']);
        self::assertFileExists($result['backup']);
        self::assertStringStartsWith($dir . '/storage/backups/', (string) $result['backup']);

        // config 回写 + 重新引导后已是最新
        $values = require $dir . '/config.php';
        self::assertSame(Kernel::VERSION, $values['penblog_version']);
        $rebooted = Kernel::boot($dir . '/config.php');
        self::assertTrue((new UpgradeService())->plan($rebooted)['upToDate']);
        self::assertNull($rebooted->updateAvailable());
    }

    public function testFailedMigrationRollsBackFromBackup(): void
    {
        [$kernel, $dir] = $this->bootInstalled('0.1.0-test');
        $kernel->container->get(Migrator::class)->register('9998-boom', new class implements Migration {
            public function up(Schema $schema): void
            {
                $schema->raw('CREATE TABLE boom_tbl (id INTEGER PRIMARY KEY)');
                throw new \RuntimeException('模拟迁移失败');
            }
        });

        try {
            (new UpgradeService())->run($kernel);
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('已回滚至备份', $e->getMessage());
        }

        // 备份存在、账本无 9998、config 版本未回写、库仍可用
        self::assertNotEmpty(glob($dir . '/storage/backups/*.db'));
        self::assertNotContains('9998-boom', $kernel->container->get(Migrator::class)->applied());
        $values = require $dir . '/config.php';
        self::assertSame('0.1.0-test', $values['penblog_version']);
        self::assertGreaterThan(0, (int) $kernel->db()->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testAdminBannerShowsUpgradeHint(): void
    {
        [$kernel] = $this->bootInstalled('0.1.0-test');

        $login = $kernel->handle($this->request('POST', '/api/v1/auth/login', ['username' => 'admin', 'password' => 'password123']));
        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());
        $cookies = self::parseSetCookies($login->getHeader('Set-Cookie'));

        $res = $kernel->handle($this->request('GET', '/admin/dashboard', null, [], $cookies));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        self::assertStringContainsString('检测到新版本', $html);
        self::assertStringContainsString('0.1.0-test', $html);
        self::assertStringContainsString('bin/migrate.php', $html);
    }

    public function testCliDryRunAndRepair(): void
    {
        [$kernel, $dir] = $this->bootInstalled('0.1.0-test');
        unset($kernel);

        $bin = dirname(__DIR__, 2) . '/bin';
        $out = [];
        exec('php ' . $bin . '/migrate.php ' . escapeshellarg($dir . '/config.php') . ' --dry-run 2>&1', $out, $code);
        $text = implode("\n", $out);
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('0.1.0-test', $text);
        self::assertStringContainsString('dry-run', $text);

        // 修复模式:空账本的新库补跑全部迁移,不动用户数据
        $dir2 = $this->makeDir();
        mkdir($dir2 . '/storage', 0777, true);
        $config2 = [
            'penblog_version' => Kernel::VERSION,
            'app' => ['debug' => true, 'url' => 'http://localhost', 'secret_key' => str_repeat('k', 32)],
            'db' => ['driver' => 'sqlite', 'path' => $dir2 . '/storage/data.db'],
            'paths' => ['logs' => $dir2 . '/storage/app.log', 'backups' => $dir2 . '/storage/backups'],
        ];
        file_put_contents($dir2 . '/config.php', "<?php\n\nreturn " . var_export($config2, true) . ";\n");
        $out2 = [];
        exec('php ' . $bin . '/install.php --repair ' . escapeshellarg($dir2 . '/config.php') . ' 2>&1', $out2, $code2);
        $text2 = implode("\n", $out2);
        self::assertSame(0, $code2, $text2);
        self::assertStringContainsString('0001-initial-schema', $text2);
        self::assertStringContainsString('0005-webhook-schema', $text2);
    }

    // ---------------------------------------------------------------- 助手

    /**
     * 安装态站点:文件库 + 迁移 + 种子(L0-L3 + admin),config 记录旧版本,经 boot 引导。
     * @return array{0: Kernel, 1: string}
     */
    private function bootInstalled(string $recordedVersion): array
    {
        $dir = $this->makeDir();
        mkdir($dir . '/storage', 0777, true);
        $config = [
            'penblog_version' => $recordedVersion,
            'app' => ['debug' => true, 'url' => 'http://localhost', 'secret_key' => str_repeat('k', 32)],
            'db' => ['driver' => 'sqlite', 'path' => $dir . '/storage/data.db'],
            'paths' => ['logs' => $dir . '/storage/app.log', 'backups' => $dir . '/storage/backups'],
        ];
        $configPath = $dir . '/config.php';
        file_put_contents($configPath, "<?php\n\nreturn " . var_export($config, true) . ";\n");

        $seed = Kernel::fromConfig($config);
        $seed->container->get(Migrator::class)->run();
        foreach (AccessLevel::defaultDefinitions() as $def) {
            $seed->extensions()->register($def);
        }
        $seed->container->get(UserRepository::class)->create('admin', 'admin@example.com', 'password123', 0);

        return [Kernel::boot($configPath), $dir];
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir() . '/pb-upg-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /** @param array<string, mixed>|null $json @param array<string, string> $cookies */
    private function request(string $method, string $path, ?array $json = null, array $headers = [], array $cookies = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $req = $factory->createServerRequest($method, $path);
        if ($json !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode($json, JSON_UNESCAPED_UNICODE) ?: ''));
        }
        foreach ($headers as $n => $v) {
            $req = $req->withHeader($n, $v);
        }
        if ($cookies !== []) {
            $req = $req->withCookieParams($cookies);
        }
        return $req;
    }

    /** @param list<string> $setCookies @return array<string, string> */
    private static function parseSetCookies(array $setCookies): array
    {
        $out = [];
        foreach ($setCookies as $line) {
            $pair = explode(';', $line, 2)[0];
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $out[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            }
        }
        return $out;
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
