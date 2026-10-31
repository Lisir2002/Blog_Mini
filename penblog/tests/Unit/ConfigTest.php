<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit;

use PenBlog\Kernel\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testNestedKeyLookup(): void
    {
        $config = new Config(['db' => ['driver' => 'sqlite', 'path' => '/tmp/a.db']]);
        self::assertSame('sqlite', $config->get('db.driver'));
        self::assertSame('fallback', $config->get('db.missing', 'fallback'));
    }

    public function testEnvOverridesValue(): void
    {
        $config = new Config(['db' => ['driver' => 'sqlite']], ['PENBLOG_DB_DRIVER' => 'mysql']);
        self::assertSame('mysql', $config->get('db.driver'));
    }

    public function testEnvCastsScalars(): void
    {
        $config = new Config([], ['PENBLOG_APP_DEBUG' => 'true', 'PENBLOG_SERVER_PORT' => '8080']);
        self::assertTrue($config->get('app.debug'));
        self::assertSame(8080, $config->get('server.port'));
    }
}
