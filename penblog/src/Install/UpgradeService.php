<?php

declare(strict_types=1);

namespace PenBlog\Install;

use PenBlog\Kernel\Kernel;
use PenBlog\Repository\Migrator;

/**
 * 升级与修复(10 §5):版本比对 → 自动备份 storage/backups/时间戳 →
 * 增量迁移 → 成功回写 config 版本;失败自动从备份恢复。
 */
final class UpgradeService
{
    /**
     * 升级计划:当前/目标版本与待应用迁移。
     * @return array{from:string, to:string, pending:list<string>, upToDate:bool}
     */
    public function plan(Kernel $kernel): array
    {
        $from = (string) $kernel->config->get('penblog_version', '');
        $pending = $kernel->container->get(Migrator::class)->pending();
        return [
            'from' => $from,
            'to' => Kernel::VERSION,
            'pending' => $pending,
            'upToDate' => $from === Kernel::VERSION && $pending === [],
        ];
    }

    /**
     * 执行升级(备份 → 迁移 → 回写版本;任一步失败回滚备份)。
     * @return array{from:string, to:string, backup:?string, migrations:list<string>}
     */
    public function run(Kernel $kernel): array
    {
        $plan = $this->plan($kernel);
        $backup = $this->backup($kernel);

        try {
            $ran = $kernel->container->get(Migrator::class)->run();
            $this->writeVersion($kernel);
        } catch (\Throwable $e) {
            if ($backup !== null) {
                $this->restore($kernel, $backup);
            }
            throw new \RuntimeException('升级失败,已回滚至备份: ' . $e->getMessage(), 0, $e);
        }

        return [
            'from' => $plan['from'],
            'to' => Kernel::VERSION,
            'backup' => $backup,
            'migrations' => $ran,
        ];
    }

    /** 修复模式(10 §5):仅补跑缺失迁移,不改用户数据、不动 config 版本。 @return list<string> */
    public function repair(Kernel $kernel): array
    {
        return $kernel->container->get(Migrator::class)->run();
    }

    /** SQLite 文件复制 / MySQL mysqldump;内存库无法备份返回 null。 */
    private function backup(Kernel $kernel): ?string
    {
        $dir = (string) $kernel->config->get('paths.backups', dirname(__DIR__, 2) . '/storage/backups');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建备份目录: ' . $dir);
        }
        $stamp = gmdate('Ymd-His');
        $driver = (string) $kernel->config->get('db.driver', 'sqlite');

        if ($driver === 'sqlite') {
            $path = (string) $kernel->config->get('db.path', '');
            if ($path === '' || $path === ':memory:' || !is_file($path)) {
                return null; // 内存库/缺失文件:无备份可做
            }
            $target = $dir . '/' . $stamp . '.db';
            if (!copy($path, $target)) {
                throw new \RuntimeException('备份失败: ' . $path);
            }
            return $target;
        }

        if ($driver === 'mysql') {
            $target = $dir . '/' . $stamp . '.sql';
            $cfg = (array) $kernel->config->get('db', []);
            $pass = (string) ($cfg['password'] ?? '');
            $cmd = sprintf(
                'mysqldump -h %s -P %d -u %s%s %s',
                escapeshellarg((string) ($cfg['host'] ?? '127.0.0.1')),
                (int) ($cfg['port'] ?? 3306),
                escapeshellarg((string) ($cfg['username'] ?? 'root')),
                $pass !== '' ? ' -p' . escapeshellarg($pass) : '',
                escapeshellarg((string) ($cfg['database'] ?? 'penblog')),
            );
            exec($cmd . ' > ' . escapeshellarg($target) . ' 2>/dev/null', $output, $code);
            if ($code !== 0 || !is_file($target) || filesize($target) === 0) {
                @unlink($target);
                throw new \RuntimeException('mysqldump 备份失败,请确认 mysql 客户端可用');
            }
            return $target;
        }

        throw new \RuntimeException("未知数据库驱动,无法备份: {$driver}");
    }

    private function restore(Kernel $kernel, string $backup): void
    {
        $driver = (string) $kernel->config->get('db.driver', 'sqlite');
        if ($driver === 'sqlite') {
            $path = (string) $kernel->config->get('db.path', '');
            if ($path !== '' && is_file($backup)) {
                copy($backup, $path);
            }
            return;
        }
        if ($driver === 'mysql') {
            $cfg = (array) $kernel->config->get('db', []);
            $cmd = sprintf(
                'mysql -h %s -P %d -u %s %s < %s',
                escapeshellarg((string) ($cfg['host'] ?? '127.0.0.1')),
                (int) ($cfg['port'] ?? 3306),
                escapeshellarg((string) ($cfg['username'] ?? 'root')),
                escapeshellarg((string) ($cfg['database'] ?? 'penblog')),
                escapeshellarg($backup),
            );
            exec($cmd . ' 2>/dev/null', $output, $code);
            if ($code !== 0) {
                throw new \RuntimeException('MySQL 回滚失败,请手工导入备份: ' . $backup);
            }
        }
    }

    /** 升级成功后原子回写 penblog_version(仅当 config 来自文件)。 */
    private function writeVersion(Kernel $kernel): void
    {
        $configPath = $kernel->configPath();
        if ($configPath === null || !is_file($configPath)) {
            return;
        }
        $values = $kernel->config->all();
        $values['penblog_version'] = Kernel::VERSION;
        $php = "<?php\n\nreturn " . var_export($values, true) . ";\n";
        $tmp = $configPath . '.tmp';
        if (file_put_contents($tmp, $php) === false || !rename($tmp, $configPath)) {
            @unlink($tmp);
            throw new \RuntimeException('无法回写 config 版本: ' . $configPath);
        }
        @chmod($configPath, 0640);
    }
}
