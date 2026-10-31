<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * §3.5 溢出检测/布局红线审计进 CI:构建期静态门禁(无浏览器沙箱可用时的第一道闸)。
 * 运行时 scrollWidth 断言由冒烟阶段浏览器矩阵执行。
 */
final class LayoutAuditTest extends TestCase
{
    public function testLayoutAuditPasses(): void
    {
        $script = dirname(__DIR__, 2) . '/bin/check-layout.php';
        $output = [];
        $code = 0;
        exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, "布局红线审计失败:\n" . implode("\n", $output));
    }
}
