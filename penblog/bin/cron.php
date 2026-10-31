<?php

declare(strict_types=1);

/**
 * 重试调度 CLI(05 §6 / 06 §8):通知管线 + Webhook 投递的到期重试。
 * crontab 建议:* * * * * php /path/to/penblog/bin/cron.php >> /dev/null 2>&1
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use PenBlog\Kernel\Kernel;

$kernel = Kernel::boot();
if ($kernel->isInstalling()) {
    fwrite(STDERR, "PenBlog 尚未安装,跳过。\n");
    exit(1);
}

$notifications = $kernel->container->get(\PenBlog\Notification\NotificationPipeline::class)->retryDue();
$webhooks = $kernel->container->get(\PenBlog\Webhook\WebhookDispatcher::class)->retryDue();

printf("通知重试 %d 条,Webhook 重试 %d 条(%s)\n", $notifications, $webhooks, gmdate('c'));
