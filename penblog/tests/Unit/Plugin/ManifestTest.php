<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit\Plugin;

use PenBlog\Plugin\Manifest;
use PenBlog\Plugin\ManifestException;
use PHPUnit\Framework\TestCase;

/**
 * 04 §1 manifest 校验单测。
 */
final class ManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'name' => 'seo-tools',
            'version' => '1.2.0',
            'requires' => ['penblog' => '>=0.1', 'php' => '>=8.2', 'plugins' => ['hello-world']],
            'entry' => 'SeoTools\\Plugin',
            'permissions' => ['router.register', 'option.read', 'event.listen'],
            'secretRefs' => ['yandex_token'],
            'description' => 'SEO 工具集',
        ];
    }

    public function testValidManifestRoundTrip(): void
    {
        $m = Manifest::fromArray(self::valid());
        self::assertSame('seo-tools', $m->name);
        self::assertSame('1.2.0', $m->version);
        self::assertSame('SeoTools\\Plugin', $m->entry);
        self::assertSame(['hello-world'], $m->requires['plugins']);
        self::assertTrue($m->hasPermission('router.register'));
        self::assertFalse($m->hasPermission('secret.write'));
        self::assertSame(['yandex_token'], $m->secretRefs);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidCases(): array
    {
        return [
            'bad name' => [['name' => 'SEO_Tools'], 'name'],
            'empty name' => [['name' => ''], 'name'],
            'bad semver' => [['version' => '1.0'], 'version'],
            'entry without namespace' => [['entry' => 'Plugin'], 'entry'],
            'unknown permission' => [['permissions' => ['db.raw']], 'permission'],
            'bad requires constraint' => [['requires' => ['php' => 'eight']], 'requires.php'],
            'bad secretRef' => [['secretRefs' => ['Bad Ref!']], 'secretRef'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCases')]
    public function testInvalidManifestRejected(array $override, string $expectedFragment): void
    {
        $json = array_merge(self::valid(), $override);
        try {
            Manifest::fromArray($json);
            self::fail('expected ManifestException');
        } catch (ManifestException $e) {
            self::assertStringContainsString($expectedFragment, $e->getMessage());
            self::assertNotEmpty($e->errors);
        }
    }

    public function testPermissionsDeduplicated(): void
    {
        $m = Manifest::fromArray(array_merge(self::valid(), ['permissions' => ['option.read', 'option.read']]));
        self::assertSame(['option.read'], $m->permissions);
    }
}
