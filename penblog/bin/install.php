<?php

declare(strict_types=1);

/**
 * 安装向导(CLI,10 §3):委托 InstallService(与 M3 Web 向导同一入口)。
 * 用法:
 *   php bin/install.php --db=sqlite [--path=storage/data.db]
 *   php bin/install.php --db=mysql --host=127.0.0.1 --dbname=penblog --user=root --pass=secret
 *   通用:--admin-user= --admin-pass= --admin-email= --site-title= --url= [--force]
 */

use PenBlog\Install\InstallPlan;
use PenBlog\Install\InstallService;

require __DIR__ . '/../vendor/autoload.php';

$options = getopt('', [
    'db::', 'path::', 'host::', 'port::', 'dbname::', 'user::', 'pass::',
    'admin-user::', 'admin-pass::', 'admin-email::', 'site-title::', 'url::', 'force',
]);

$db = $options['db'] ?? 'sqlite';
$rootDir = dirname(__DIR__);

$dbConfig = match ($db) {
    'sqlite' => [
        'driver' => 'sqlite',
        'path' => $options['path'] ?? $rootDir . '/storage/data.db',
    ],
    'mysql' => [
        'driver' => 'mysql',
        'host' => $options['host'] ?? '127.0.0.1',
        'port' => (int) ($options['port'] ?? 3306),
        'database' => $options['dbname'] ?? 'penblog',
        'username' => $options['user'] ?? 'root',
        'password' => $options['pass'] ?? '',
    ],
    default => exit(fwrite(STDERR, "Unknown db driver: {$db}\n") ?: 1),
};

$plan = new InstallPlan(
    dbConfig: $dbConfig,
    siteTitle: (string) ($options['site-title'] ?? 'My PenBlog'),
    siteUrl: (string) ($options['url'] ?? 'http://localhost:8080'),
    adminUser: (string) ($options['admin-user'] ?? 'admin'),
    adminEmail: (string) ($options['admin-email'] ?? 'admin@example.com'),
    adminPass: isset($options['admin-pass']) ? (string) $options['admin-pass'] : null,
    force: array_key_exists('force', $options),
    rootDir: $rootDir,
    configPath: $rootDir . '/config/config.php',
);

try {
    $result = (new InstallService())->run($plan);
} catch (\Throwable $e) {
    fwrite(STDERR, '安装失败: ' . $e->getMessage() . "\n");
    exit(1);
}

echo 'Migrations applied: ' . (implode(', ', $result['migrations']) ?: 'none (already up to date)') . "\n";
echo "Installed.\n";
echo "  admin user: {$plan->adminUser}\n";
if ($result['passGenerated']) {
    echo "  admin pass: {$result['adminPass']}   (随机生成,请立刻修改)\n";
}
echo "  config:     {$plan->configPath}\n";
echo "Next: php -S 0.0.0.0:8080 -t public\n";
