<?php

declare(strict_types=1);

namespace PenBlog\Support;

/**
 * 安全 Markdown 渲染(11 §1/§2):CommonMark + GFM 扩展(表格/任务列表/删除线/
 * 自动链接/围栏代码/图片/有序列表)+ 脚注。
 * 安全红线:先整体转义再结构替换(等价 html_input=escape),原始 HTML 永不进入输出;
 * URL 仅允许 http/https/mailto 或相对路径;正文输入上限 200KB。
 */
final class Markdown
{
    public const MAX_INPUT = 200_000;

    /** 摘要分隔符(独立成行):上半部为摘要。 */
    public const BREAK_MARKER = '<!--break-->';

    /** @return array{bodyMd: string, excerptMd: ?string} */
    public static function splitBreak(string $md): array
    {
        $pos = strpos($md, "\n" . self::BREAK_MARKER);
        if ($pos === false && !str_starts_with($md, self::BREAK_MARKER)) {
            return ['bodyMd' => $md, 'excerptMd' => null];
        }
        $excerpt = $pos === false ? '' : substr($md, 0, $pos);
        $body = $pos === false ? substr($md, strlen(self::BREAK_MARKER)) : substr($md, $pos + 1);
        return ['bodyMd' => ltrim($body, "\n"), 'excerptMd' => trim($excerpt) !== '' ? trim($excerpt) : null];
    }

    public static function toHtml(string $md): string
    {
        if (strlen($md) > self::MAX_INPUT) {
            $md = substr($md, 0, self::MAX_INPUT);
        }
        $escaped = htmlspecialchars($md, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 围栏代码块先行抽出(内容不再参与行内替换)。占位符用 \x02(非 rtrim 空白集,防行尾被裁)。
        $blocks = [];
        $escaped = preg_replace_callback(
            '/```([\w+-]*)\n(.*?)```/s',
            static function (array $m) use (&$blocks): string {
                $lang = $m[1] !== '' ? ' class="language-' . htmlspecialchars_decode($m[1]) . '"' : '';
                $blocks[] = '<pre class="code-block"><code' . $lang . '>' . rtrim($m[2]) . '</code></pre>';
                return "\x02B" . (count($blocks) - 1) . "\x02";
            },
            $escaped,
        ) ?? $escaped;

        // 脚注定义行抽出: [^id]: 文本
        $notes = [];
        $escaped = preg_replace_callback(
            '/^\[\^([^\]\s]{1,32})\]:\s*(.*)$/m',
            static function (array $m) use (&$notes): string {
                $notes[self::safeId($m[1])] = $m[2];
                return '';
            },
            $escaped,
        ) ?? $escaped;

        $lines = explode("\n", $escaped);
        $html = [];
        $para = [];
        $list = null;      // 'ul' | 'ol'
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
        $flushAll = static function () use ($flushPara, $flushList, $flushQuote): void {
            $flushPara();
            $flushList();
            $flushQuote();
        };

        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            $t = rtrim($lines[$i]);
            if ($t === '' || $t === '&lt;!--break--&gt;') {
                $flushAll();
                continue;
            }
            // 标题 h1-h6
            if (preg_match('/^(#{1,6})\s+(.*)$/', $t, $m)) {
                $flushAll();
                $level = strlen($m[1]);
                $html[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }
            // 分割线
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $t)) {
                $flushAll();
                $html[] = '<hr>';
                continue;
            }
            // 引用
            if (preg_match('/^&gt;\s?(.*)$/', $t, $m)) {
                $flushPara();
                $flushList();
                $quote[] = self::inline($m[1]);
                continue;
            }
            // 任务列表
            if (preg_match('/^[-*]\s+\[([ xX])\]\s+(.*)$/', $t, $m)) {
                $flushPara();
                $flushQuote();
                if ($list !== 'ul') {
                    $flushList();
                    $html[] = '<ul class="task-list">';
                    $list = 'ul';
                }
                $checked = $m[1] === ' ' ? '' : ' checked';
                $html[] = '<li class="task"><label><input type="checkbox" disabled' . $checked . '> '
                    . self::inline($m[2]) . '</label></li>';
                continue;
            }
            // 无序列表
            if (preg_match('/^[-*]\s+(.*)$/', $t, $m)) {
                $flushPara();
                $flushQuote();
                if ($list !== 'ul') {
                    $flushList();
                    $html[] = '<ul>';
                    $list = 'ul';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            // 有序列表
            if (preg_match('/^\d+\.\s+(.*)$/', $t, $m)) {
                $flushPara();
                $flushQuote();
                if ($list !== 'ol') {
                    $flushList();
                    $html[] = '<ol>';
                    $list = 'ol';
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            // GFM 表格:表头行 + 分隔行
            if (str_starts_with($t, '|') && isset($lines[$i + 1])
                && preg_match('/^\|?[\s:|-]+\|[\s:|-]*$/', rtrim($lines[$i + 1])) === 1
                && str_contains($lines[$i + 1], '-')) {
                $flushAll();
                $aligns = array_map(
                    static function (string $c): string {
                        $c = trim($c);
                        return match (true) {
                            preg_match('/^:\s*-+\s*:$/', $c) === 1 => 'center',
                            preg_match('/^-+\s*:$/', $c) === 1 => 'right',
                            default => 'left',
                        };
                    },
                    self::tableCells($lines[$i + 1]),
                );
                $head = self::tableCells($t);
                $rowHtml = static function (array $cells, array $aligns, string $tag): string {
                    $out = '';
                    foreach ($cells as $j => $c) {
                        $align = $aligns[$j] ?? 'left';
                        $style = $align === 'left' ? '' : ' style="text-align:' . $align . '"';
                        $out .= "<{$tag}{$style}>" . self::inline(trim($c)) . "</{$tag}>";
                    }
                    return '<tr>' . $out . '</tr>';
                };
                $table = '<div class="table-wrap"><table><thead>' . $rowHtml($head, $aligns, 'th') . '</thead><tbody>';
                $i++; // 跳过分隔行
                while (isset($lines[$i + 1]) && str_starts_with(rtrim($lines[$i + 1]), '|')) {
                    $i++;
                    $table .= $rowHtml(self::tableCells($lines[$i]), $aligns, 'td');
                }
                $html[] = $table . '</tbody></table></div>';
                continue;
            }
            // 围栏代码块占位符独立成行(避免被包进 <p>)
            if (preg_match('/^\x02B\d+\x02$/', $t)) {
                $flushAll();
                $html[] = $t;
                continue;
            }
            $flushList();
            $flushQuote();
            $para[] = self::inline($t);
        }
        $flushAll();

        $out = implode("\n", $html);

        // 脚注区
        if ($notes !== []) {
            $items = '';
            foreach ($notes as $id => $text) {
                $items .= '<li id="fn-' . $id . '">' . self::inline($text)
                    . ' <a href="#fnref-' . $id . '" aria-label="返回正文">↩</a></li>';
            }
            $out .= "\n<section class=\"footnotes\"><hr><ol>" . $items . '</ol></section>';
        }

        foreach ($blocks as $idx => $block) {
            $out = str_replace("\x02B{$idx}\x02", $block, $out);
        }
        return $out;
    }

    /** @return list<string> */
    private static function tableCells(string $line): array
    {
        // 先保护转义竖线 \| ,再按 | 切分(单元格内的 \x01 由 inline() 末尾还原)
        $protected = str_replace('\|', "\x01", trim(trim(rtrim($line)), '|'));
        return explode('|', $protected);
    }

    private static function inline(string $s): string
    {
        // 1) 行内码 → 占位(内容不参与后续替换)
        $codes = [];
        $s = preg_replace_callback('/`([^`\n]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . $m[1] . '</code>';
            return "\x00C" . (count($codes) - 1) . "\x00";
        }, $s) ?? $s;

        // 2) 图片(先于链接,避免 ![] 被 []() 吞掉)
        $s = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^()\s]+)\)/u',
            static function (array $m): string {
                $url = self::safeUrl(htmlspecialchars_decode($m[2], ENT_QUOTES));
                if ($url === null) {
                    return $m[0];
                }
                return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . $m[1] . '" loading="lazy">';
            },
            $s,
        ) ?? $s;

        // 3) 链接
        $s = preg_replace_callback(
            '/\[([^\]]+)\]\(([^()\s]+)\)/u',
            static function (array $m): string {
                $url = self::safeUrl(htmlspecialchars_decode($m[2], ENT_QUOTES));
                if ($url === null) {
                    return $m[0];
                }
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $m[1] . '</a>';
            },
            $s,
        ) ?? $s;

        // 4) 脚注引用 [^id]
        $s = preg_replace_callback(
            '/\[\^([^\]\s]{1,32})\]/u',
            static fn (array $m): string => '<sup id="fnref-' . self::safeId($m[1]) . '">'
                . '<a href="#fn-' . self::safeId($m[1]) . '">' . self::safeId($m[1]) . '</a></sup>',
            $s,
        ) ?? $s;

        // 5) 自动链接:&lt;https://…&gt; 与裸 URL(避开已生成的 href)
        $s = preg_replace_callback(
            '/&lt;((?:https?:\/\/|mailto:)[^\s&]+)&gt;/',
            static fn (array $m): string => '<a href="' . $m[1] . '">' . $m[1] . '</a>',
            $s,
        ) ?? $s;
        $s = preg_replace_callback(
            '/(?<!["\'=>\/\w])((?:https?):\/\/[^\s<>"\']+)/u',
            static function (array $m): string {
                $url = rtrim($m[1], '.,;:!?)');
                return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $url . '</a>';
            },
            $s,
        ) ?? $s;

        // 6) 强调:删除线 → 粗体 → 斜体
        $s = preg_replace('/~~(.+?)~~/u', '<del>$1</del>', $s) ?? $s;
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/u', '<em>$1</em>', $s) ?? $s;

        // 7) 还原行内码
        foreach ($codes as $i => $code) {
            $s = str_replace("\x00C{$i}\x00", $code, $s);
        }
        // 表格转义竖线还原
        return str_replace("\x01", '|', $s);
    }

    /** URL 协议白名单(11 §2):http/https/mailto 或无协议(相对路径);其余返回 null 降级纯文本。 */
    private static function safeUrl(string $u): ?string
    {
        if (preg_match('#^(?:https?://|mailto:|/|\#|\?)#i', $u) === 1) {
            return $u;
        }
        return preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $u) === 1 ? null : $u;
    }

    private static function safeId(string $id): string
    {
        return preg_replace('/[^0-9A-Za-z_-]/', '', $id) ?? '';
    }
}
