<?php

declare(strict_types=1);

/**
 * M1 安装向导(CLI):建库 → 迁移 → 管理员 → 写配置。
 * 用法:
 *   php bin/install.php --db=sqlite [--path=storage/data.db]
 *   php bin/install.php --db=mysql --host=127.0.0.1 --dbname=penblog --user=root --pass=secret
 *   通用:--admin-user= --admin-pass= --admin-email= --site-title= --url= [--force]
 */

use PenBlog\Kernel\Kernel;
use PenBlog\Repository\DriverFactory;
use PenBlog\Repository\MysqlDriver;

require __DIR__ . '/../vendor/autoload.php';

$options = getopt('', [
    'db::', 'path::', 'host::', 'port::', 'dbname::', 'user::', 'pass::',
    'admin-user::', 'admin-pass::', 'admin-email::', 'site-title::', 'url::', 'force',
]);

$db = $options['db'] ?? 'sqlite';
$adminUser = $options['admin-user'] ?? 'admin';
$adminPass = $options['admin-pass'] ?? bin2hex(random_bytes(8));
$adminEmail = $options['admin-email'] ?? 'admin@example.com';
$siteTitle = $options['site-title'] ?? 'My PenBlog';
$force = array_key_exists('force', $options);

$configPath = __DIR__ . '/../config/config.php';
if (is_file($configPath) && !$force) {
    fwrite(STDERR, "config/config.php already exists. Use --force to overwrite.\n");
    exit(1);
}

$dbConfig = match ($db) {
    'sqlite' => [
        'driver' => 'sqlite',
        'path' => $options['path'] ?? __DIR__ . '/../storage/data.db',
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

if ($db === 'mysql') {
    MysqlDriver::ensureDatabase(
        $dbConfig['host'], $dbConfig['port'], $dbConfig['database'], $dbConfig['username'], $dbConfig['password'],
    );
}

$kernel = Kernel::fromConfig([
    'app' => ['debug' => false, 'url' => $options['url'] ?? 'http://localhost:8080', 'secret_key' => null],
    'db' => $dbConfig,
    'paths' => ['logs' => __DIR__ . '/../storage/logs/app.log'],
]);

$applied = $kernel->container->get(\PenBlog\Repository\Migrator::class)->run();
echo 'Migrations applied: ' . (implode(', ', $applied) ?: 'none (already up to date)') . "\n";

$driver = $kernel->db();
$now = gmdate('Y-m-d\TH:i:s\Z');
$stmt = $driver->pdo()->prepare(
    'INSERT INTO users (username, email, password_hash, display_name, role, created_at, updated_at)
     VALUES (:u, :e, :p, :d, :r, :c, :c2)'
);
$stmt->execute([
    'u' => $adminUser,
    'e' => $adminEmail,
    'p' => password_hash($adminPass, PASSWORD_DEFAULT),
    'd' => $adminUser,
    'r' => 'admin',
    'c' => $now,
    'c2' => $now,
]);

$kernel->options()->set('site.title', $siteTitle);
$kernel->options()->set('site.url', $options['url'] ?? 'http://localhost:8080');
$kernel->options()->set('install.db', $db);

$configFile = [
    'app' => ['debug' => false, 'url' => $options['url'] ?? 'http://localhost:8080', 'secret_key' => bin2hex(random_bytes(32))],
    'db' => $dbConfig,
    'paths' => [
        'logs' => __DIR__ . '/../storage/logs/app.log',
        'themes' => __DIR__ . '/../themes',
        'plugins' => __DIR__ . '/../plugins',
    ],
];
$export = var_export($configFile, true);
file_put_contents($configPath, "<?php\n\nreturn {$export};\n");

echo "Installed.\n";
echo "  admin user: {$adminUser}\n";
if (($options['admin-pass'] ?? null) === null) {
    echo "  admin pass: {$adminPass}   (随机生成,请立刻修改)\n";
}
echo "  config:     {$configPath}\n";
echo "Next: php -S 0.0.0.0:8080 -t public\n";
