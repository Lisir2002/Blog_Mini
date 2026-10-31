<?php

declare(strict_types=1);

/**
 * 升级迁移(10 §5):版本比对 → 自动备份 storage/backups/ → 增量迁移 → 回写 config 版本;
 * 迁移失败自动从备份回滚。
 * 用法:php bin/migrate.php [config路径] [--dry-run]
 */

use PenBlog\Install\UpgradeService;
use PenBlog\Kernel\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

$argvCopy = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $argvCopy, true);
$configPath = null;
foreach ($argvCopy as $arg) {
    if (!str_starts_with($arg, '--')) {
        $configPath = $arg;
    }
}

$kernel = Kernel::boot($configPath);
if ($kernel->isInstalling()) {
    fwrite(STDERR, "站点未安装,请先运行 php bin/install.php 或 Web 向导\n");
    exit(1);
}

$service = new UpgradeService();
$plan = $service->plan($kernel);
echo "当前版本: {$plan['from']} → 目标版本: {$plan['to']}\n";
echo "待应用迁移: " . ($plan['pending'] === [] ? '无' : implode(', ', $plan['pending'])) . "\n";

if ($plan['upToDate']) {
    echo "已是最新,无需升级。\n";
    exit(0);
}
if ($dryRun) {
    echo "--dry-run:未做任何变更。\n";
    exit(0);
}

try {
    $result = $service->run($kernel);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo '备份: ' . ($result['backup'] ?? '(内存库,跳过)') . "\n";
echo '已应用: ' . ($result['migrations'] === [] ? '无(仅版本回写)' : implode(', ', $result['migrations'])) . "\n";
echo "升级完成 {$result['from']} → {$result['to']}\n";
