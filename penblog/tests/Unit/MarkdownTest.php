<?php

declare(strict_types=1);

namespace PenBlog\Tests\Unit;

use PenBlog\Support\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * GFM 语法基线 + 安全红线(11 §1/§2):CommonMark + 表格/任务列表/删除线/
 * 自动链接/围栏代码/图片/有序列表/脚注;原始 HTML 转义、URL 协议白名单。
 */
final class MarkdownTest extends TestCase
{
    public function testHeadingsH1ToH6(): void
    {
        for ($level = 1; $level <= 6; $level++) {
            $md = str_repeat('#', $level) . ' 标题' . $level;
            self::assertStringContainsString("<h{$level}>标题{$level}</h{$level}>", Markdown::toHtml($md));
        }
    }

    public function testEmphasisAndInlineCode(): void
    {
        $html = Markdown::toHtml('**粗** 和 *斜* 和 ~~删~~ 和 `a*b*c`');
        self::assertStringContainsString('<strong>粗</strong>', $html);
        self::assertStringContainsString('<em>斜</em>', $html);
        self::assertStringContainsString('<del>删</del>', $html);
        self::assertStringContainsString('<code>a*b*c</code>', $html);
        self::assertStringNotContainsString('<em>a*b*c</em>', $html);
    }

    public function testLinkAndImageSafeUrls(): void
    {
        $html = Markdown::toHtml('[站点](https://example.com) 与 ![图](/img/a.png)');
        self::assertStringContainsString('<a href="https://example.com">站点</a>', $html);
        self::assertStringContainsString('<img src="/img/a.png" alt="图" loading="lazy">', $html);
    }

    public function testDangerousProtocolsDowngradedToText(): void
    {
        $html = Markdown::toHtml('[x](javascript:alert) 与 ![y](data:image/svg+xml,1)');
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringNotContainsString('<a href="data:', $html);
        self::assertStringNotContainsString('<img src="data:', $html);
        self::assertStringContainsString('[x](javascript:alert)', $html);
    }

    public function testAutoLinks(): void
    {
        $html = Markdown::toHtml('访问 <https://example.com> 或 https://a.example.cn/x 结束');
        self::assertStringContainsString('<a href="https://example.com">https://example.com</a>', $html);
        self::assertStringContainsString('<a href="https://a.example.cn/x">https://a.example.cn/x</a>', $html);
    }

    public function testLists(): void
    {
        $html = Markdown::toHtml("- 甲\n- 乙");
        self::assertStringContainsString("<ul>\n<li>甲</li>\n<li>乙</li>\n</ul>", $html);

        $html = Markdown::toHtml("1. 一\n2. 二");
        self::assertStringContainsString("<ol>\n<li>一</li>\n<li>二</li>\n</ol>", $html);

        $html = Markdown::toHtml("- [x] 完成\n- [ ] 未完成");
        self::assertStringContainsString('<ul class="task-list">', $html);
        self::assertStringContainsString('<input type="checkbox" disabled checked>', $html);
        self::assertStringContainsString('<input type="checkbox" disabled>', $html);
    }

    public function testBlockquoteAndHr(): void
    {
        $html = Markdown::toHtml("> 引用一句\n\n---\n\n正文");
        self::assertStringContainsString('<blockquote>引用一句</blockquote>', $html);
        self::assertStringContainsString('<hr>', $html);
    }

    public function testFencedCodeBlockWithLanguage(): void
    {
        $html = Markdown::toHtml("```php\n\$x = '**不是粗体**';\n```");
        self::assertStringContainsString('<pre class="code-block"><code class="language-php">', $html);
        self::assertStringContainsString('不是粗体', $html);
        self::assertStringNotContainsString('<strong>', $html);
    }

    public function testGfmTableWithAlignmentAndEscapedPipe(): void
    {
        $md = "| A | B |\n| :-- | --: |\n| 1 \\| 一 | 2 |";
        $html = Markdown::toHtml($md);
        self::assertStringContainsString('<div class="table-wrap"><table>', $html);
        self::assertStringContainsString('<th>A</th>', $html);
        self::assertStringContainsString('<th style="text-align:right">B</th>', $html);
        self::assertStringContainsString('<td>1 | 一</td>', $html);
        self::assertStringContainsString('<td style="text-align:right">2</td>', $html);
    }

    public function testFootnotes(): void
    {
        $html = Markdown::toHtml("论点[^1]\n\n[^1]: 出处说明");
        self::assertStringContainsString('<sup id="fnref-1"><a href="#fn-1">1</a></sup>', $html);
        self::assertStringContainsString('<section class="footnotes">', $html);
        self::assertStringContainsString('<li id="fn-1">出处说明', $html);
    }

    public function testRawHtmlIsEscaped(): void
    {
        $html = Markdown::toHtml("<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>");
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testInputTruncatedAtMaxSize(): void
    {
        $html = Markdown::toHtml(str_repeat('a', Markdown::MAX_INPUT + 10_000));
        self::assertLessThanOrEqual(Markdown::MAX_INPUT + 64, strlen($html));
    }

    public function testSplitBreak(): void
    {
        $parts = Markdown::splitBreak("导语段\n<!--break-->\n正文**粗**");
        self::assertSame('导语段', $parts['excerptMd']);
        self::assertStringContainsString('正文', $parts['bodyMd']);

        $parts = Markdown::splitBreak("没有分隔符的全文");
        self::assertNull($parts['excerptMd']);
        self::assertSame('没有分隔符的全文', $parts['bodyMd']);
    }

    public function testBreakMarkerNotRendered(): void
    {
        $html = Markdown::toHtml("上\n<!--break-->\n下");
        self::assertStringNotContainsString('break', $html);
        self::assertStringContainsString('<p>下</p>', $html);
    }
}
