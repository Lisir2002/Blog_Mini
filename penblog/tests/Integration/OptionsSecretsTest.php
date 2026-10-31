<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

final class OptionsSecretsTest extends KernelTestCase
{
    public function testOptionsRoundTripJsonTypes(): void
    {
        $options = $this->kernel->options();
        $options->set('theme.default.layout', 'grid');
        $options->set('theme.default.per_page', 10);
        $options->set('theme.default.show_excerpt', true);

        self::assertSame('grid', $options->get('theme.default.layout'));
        self::assertSame(10, $options->get('theme.default.per_page'));
        self::assertTrue($options->get('theme.default.show_excerpt'));
        self::assertSame('fallback', $options->get('missing.key', 'fallback'));
    }

    public function testOptionsUpsertOverwrites(): void
    {
        $options = $this->kernel->options();
        $options->set('site.title', 'A');
        $options->set('site.title', 'B');
        self::assertSame('B', $options->get('site.title'));
    }

    public function testAllWithPrefix(): void
    {
        $options = $this->kernel->options();
        $options->set('theme.a.x', 1);
        $options->set('theme.a.y', 2);
        $options->set('other.z', 3);
        $all = $options->allWithPrefix('theme.a.');
        self::assertSame(['theme.a.x' => 1, 'theme.a.y' => 2], $all);
    }

    public function testSecretRoundTripWithEncryption(): void
    {
        $secrets = $this->kernel->secrets();
        $secrets->put('smtp_password', 'sup3r-s3cret');
        self::assertSame('sup3r-s3cret', $secrets->get('smtp_password'));
        self::assertTrue($secrets->has('smtp_password'));

        // 密文入库:原始行不应含明文
        $row = $this->kernel->db()->pdo()->query("SELECT value FROM secrets WHERE name = 'smtp_password'")->fetch();
        self::assertStringNotContainsString('sup3r-s3cret', (string) $row['value']);
    }

    public function testSecretDelete(): void
    {
        $secrets = $this->kernel->secrets();
        $secrets->put('token', 'abc');
        $secrets->delete('token');
        self::assertNull($secrets->get('token'));
    }
}
