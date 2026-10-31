<?php

declare(strict_types=1);

namespace PenBlog\Support;

/**
 * 极简安全 Markdown(08 §1 渐进增强,M2 骨架级):
 * 先整体转义再做结构替换,任何原始 HTML 都不会进入输出。
 */
final class Markdown
{
    public static function toHtml(string $md): string
    {
        $escaped = htmlspecialchars($md, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 围栏代码块
        $blocks = [];
        $escaped = preg_replace_callback('/```([\w-]*)\n(.*?)```/s', static function (array $m) use (&$blocks): string {
            $blocks[] = '<pre class="code-block"><code>' . trim($m[2]) . '</code></pre>';
            return "\x00BLOCK" . (count($blocks) - 1) . "\x00";
        }, $escaped) ?? $escaped;

        $lines = explode("\n", $escaped);
        $html = [];
        $para = [];
        $list = null; // 'ul'
        $quote = [];

        $flushPara = static function () use (&$para, &$html): void {
            if ($para !== []) {
                $html[] = '<p>' . implode('<br>', $para) . '</p>';
                $para = [];
            }
        };
        $flushList = static function () use (&$list, &$html): void {
            if ($list !== null) {
                $html[] = '</' . $list . '>';
                $list = null;
            }
        };
        $flushQuote = static function () use (&$quote, &$html): void {
            if ($quote !== []) {
                $html[] = '<blockquote>' . implode('<br>', $quote) . '</blockquote>';
                $quote = [];
            }
        };

        foreach ($lines as $line) {
            $t = rtrim($line);
            if ($t === '') {
                $flushPara();
                $flushList();
                $flushQuote();
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.*)$/', $t, $m)) {
                $flushPara();
                $flushList();
                $flushQuote();
                $level = strlen($m[1]);
                $html[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }
            if (preg_match('/^&gt;\s?(.*)$/', $t, $m)) {
                $flushPara();
                $flushList();
                $quote[] = self::inline($m[1]);
                continue;
            }
            if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
                $flushPara();
                $flushQuote();
                if ($list === null) {
                    $html[] = '<ul>';
                    $list = 'ul';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if (preg_match('/^<hr\s*\/?>$/i', $t)) {
                $flushPara();
                $html[] = '<hr>';
                continue;
            }
            $flushList();
            $flushQuote();
            $para[] = self::inline($t);
        }
        $flushPara();
        $flushList();
        $flushQuote();

        $out = implode("\n", $html);
        foreach ($blocks as $i => $block) {
            $out = str_replace("\x00BLOCK{$i}\x00", $block, $out);
        }
        return $out;
    }

    private static function inline(string $s): string
    {
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/u', '<em>$1</em>', $s) ?? $s;
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
        // [text](https-or-relative)
        $s = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^()\s]+|\/[^()\s]*)\)/u',
            static fn (array $m): string => '<a href="' . $m[2] . '">' . $m[1] . '</a>',
            $s,
        ) ?? $s;
        return $s;
    }
}
