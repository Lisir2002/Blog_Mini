<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit;

use PenBlog\Support\Slugger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SluggerTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function slugs(): array
    {
        return [
            ['Hello World!', 'hello-world'],
            ['PHP 8 & PDO', 'php-8-pdo'],
            ['  多篇文章 标题  ', '多篇文章-标题'],
            ['---', 'post'],
            ['CamelCase Title', 'camelcase-title'],
        ];
    }

    #[DataProvider('slugs')]
    public function testSlugGeneration(string $title, string $expected): void
    {
        self::assertSame($expected, Slugger::fromTitle($title));
    }

    public function testUniqueAppendsSuffix(): void
    {
        $taken = ['hello' => true];
        $slug = Slugger::unique('hello', static fn (string $s): bool => isset($taken[$s]));
        self::assertSame('hello-2', $slug);
    }
}
