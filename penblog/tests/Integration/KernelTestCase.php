<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Database\Migrations\InitialSchema;
use PenBlog\Kernel\Kernel;
use PenBlog\Repository\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * 集成测试基类:内存 SQLite + 完整迁移(php-testing skill 约定)。
 */
abstract class KernelTestCase extends TestCase
{
    protected Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = Kernel::fromConfig([
            'app' => ['debug' => true, 'url' => 'http://localhost', 'secret_key' => str_repeat('k', 32)],
            'db' => ['driver' => 'sqlite', 'path' => ':memory:'],
            'paths' => ['logs' => sys_get_temp_dir() . '/penblog-test.log'],
        ]);

        $migrator = new Migrator($this->kernel->db());
        $migrator->register('0001-initial-schema', new InitialSchema());
        $migrator->run();
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir() . '/penblog-test.log');
    }
}
