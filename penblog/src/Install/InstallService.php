<?php

declare(strict_types=1);

namespace PenBlog\Install;

use PenBlog\Auth\AccessLevel;
use PenBlog\Auth\PermissionResolver;
use PenBlog\Auth\UserRepository;
use PenBlog\Content\Post;
use PenBlog\Content\PostRepository;
use PenBlog\Kernel\Kernel;
use PenBlog\Kernel\Metadata;
use PenBlog\Repository\Migrator;
use PenBlog\Repository\MysqlDriver;

/**
 * 安装服务(10 §3):建库 → 迁移 → 种子 → 原子写 config。
 * CLI(bin/install.php)与 M3 Web 向导共用本入口。
 */
final class InstallService
{
    /** @return array{adminPass: string, secretKey: string, migrations: list<string>} */
    public function run(InstallPlan $plan): array
    {
        if (is_file($plan->configPath) && !$plan->force) {
            throw new \RuntimeException('config 已存在,使用 --force 覆盖重装');
        }

        $checker = new EnvironmentChecker();
        $failures = $checker->requiredFailures($plan->dbConfig, $plan->rootDir);
        if ($failures !== []) {
            throw new \RuntimeException('环境检查未通过: ' . implode(', ', $failures));
        }

        if (($plan->dbConfig['driver'] ?? '') === 'mysql') {
            MysqlDriver::ensureDatabase(
                (string) ($plan->dbConfig['host'] ?? '127.0.0.1'),
                (int) ($plan->dbConfig['port'] ?? 3306),
                (string) ($plan->dbConfig['database'] ?? 'penblog'),
                (string) ($plan->dbConfig['username'] ?? 'root'),
                (string) ($plan->dbConfig['password'] ?? ''),
            );
        }

        $secretKey = bin2hex(random_bytes(32));
        $kernel = Kernel::fromConfig([
            'app' => ['debug' => false, 'url' => $plan->siteUrl, 'secret_key' => $secretKey],
            'db' => $plan->dbConfig,
            'paths' => [
                'logs' => $plan->rootDir . '/storage/logs/app.log',
                'themes' => $plan->rootDir . '/themes',
                'plugins' => $plan->rootDir . '/plugins',
            ],
        ]);

        $migrations = $kernel->container->get(Migrator::class)->run();

        // ---- 种子(09 §1 / 10 §4)----
        foreach (AccessLevel::defaultDefinitions() as $def) {
            $kernel->extensions()->register($def);
        }

        $users = $kernel->container->get(UserRepository::class);
        $admin = $users->findByUsername($plan->adminUser);
        if ($admin === null) {
            $admin = $users->create($plan->adminUser, $plan->adminEmail, $plan->resolvedAdminPass(), 0);
        }

        $kernel->options()->set('site.title', $plan->siteTitle);
        $kernel->options()->set('site.url', $plan->siteUrl);
        $kernel->options()->set('auth.registration', 'closed');
        $kernel->options()->set('active_theme', 'paper');

        // 主题注册项(paper 随发行内置)
        $kernel->extensions()->register(new \PenBlog\Kernel\Extension(
            Metadata::generate('paper', 'Theme'),
            ['name' => 'paper', 'displayName' => 'Paper', 'version' => Kernel::VERSION, 'active' => true, 'type' => 'bundled'],
        ));

        // 首篇已发布文章
        $stmt = $kernel->db()->pdo()->prepare('SELECT COUNT(*) AS c FROM posts WHERE deleted_at IS NULL');
        $stmt->execute();
        if ((int) $stmt->fetch()['c'] === 0) {
            $gate = $kernel->container->get(PermissionResolver::class)->gate($admin);
            $kernel->container->get(PostRepository::class)->save(null, [
                'title' => 'Hello PenBlog',
                'bodyMd' => "欢迎来到 PenBlog Engine。\n\n这是安装向导创建的第一篇文章,可以在后台编辑或删除。",
                'excerpt' => '欢迎来到 PenBlog Engine。',
                'status' => 'published',
                'categories' => ['公告'],
                'tags' => ['penblog'],
            ], $gate);
        }

        // ---- 原子写 config(10 §5:tmp + rename,0640)----
        $configFile = [
            'penblog_version' => Kernel::VERSION,
            'app' => ['debug' => false, 'url' => $plan->siteUrl, 'secret_key' => $secretKey],
            'db' => $plan->dbConfig,
            'paths' => [
                'logs' => $plan->rootDir . '/storage/logs/app.log',
                'themes' => $plan->rootDir . '/themes',
                'plugins' => $plan->rootDir . '/plugins',
            ],
        ];
        $php = "<?php\n\nreturn " . var_export($configFile, true) . ";\n";
        $tmp = $plan->configPath . '.tmp';
        if (file_put_contents($tmp, $php) === false || !rename($tmp, $plan->configPath)) {
            @unlink($tmp);
            throw new \RuntimeException('无法写入 config: ' . $plan->configPath);
        }
        @chmod($plan->configPath, 0640);

        return [
            'adminPass' => $plan->resolvedAdminPass(),
            'passGenerated' => $plan->adminPass === null,
            'secretKey' => $secretKey,
            'migrations' => $migrations,
        ];
    }
}
