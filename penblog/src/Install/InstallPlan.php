<?php

declare(strict_types=1);

namespace PenBlog\Install;

/**
 * 安装计划 DTO(10 §2):Web 向导与 CLI 共用同一入口。
 */
final class InstallPlan
{
    /** @param array<string, mixed> $dbConfig */
    public function __construct(
        public readonly array $dbConfig,
        public readonly string $siteTitle = 'My PenBlog',
        public readonly string $siteUrl = 'http://localhost:8080',
        public readonly string $adminUser = 'admin',
        public readonly string $adminEmail = 'admin@example.com',
        public readonly ?string $adminPass = null,
        public readonly bool $force = false,
        public readonly string $rootDir = '',
        public readonly string $configPath = '',
    ) {
    }

    public static function defaults(array $dbConfig): self
    {
        $root = dirname(__DIR__, 2);
        return new self(
            dbConfig: $dbConfig,
            rootDir: $root,
            configPath: $root . '/config/config.php',
        );
    }

    /** 未提供密码时生成 16 位随机口令(10 §3)。 */
    public function resolvedAdminPass(): string
    {
        return $this->adminPass ?? bin2hex(random_bytes(8));
    }
}
