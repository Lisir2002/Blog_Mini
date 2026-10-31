<?php

declare(strict_types=1);

namespace PenBlog\Notification;

use PenBlog\Kernel\Config;

/**
 * 极简邮件门面:mail.driver=log(默认,写 storage/logs/mail.log,测试/开发用)
 * 或 mail(系统 sendmail)。不引入外部依赖。
 */
final class Mailer
{
    public function __construct(
        private readonly string $driver = 'log',
        private readonly string $logPath = 'storage/logs/mail.log',
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            (string) $config->get('mail.driver', 'log'),
            (string) $config->get('paths.mail', 'storage/logs/mail.log'),
        );
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->driver === 'mail') {
            return @mail($to, $subject, $body);
        }
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        return @file_put_contents(
            $this->logPath,
            sprintf("[%s] to=%s subject=%s\n%s\n\n", gmdate('c'), $to, $subject, $body),
            FILE_APPEND,
        ) !== false;
    }
}
