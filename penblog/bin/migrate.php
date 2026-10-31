<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = PenBlog\Kernel\Kernel::boot($argv[1] ?? null);
if ($kernel->isInstalling()) {
    fwrite(STDERR, "站点未安装,请先运行安装向导\n");
    exit(1);
}
$migrator = $kernel->container->get(PenBlog\Repository\Migrator::class);
$migrator->run();
echo "迁移完成:db=" . $kernel->db()->name() . PHP_EOL;
