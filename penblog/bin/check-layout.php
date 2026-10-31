<?php

declare(strict_types=1);

/**
 * §3.2 防错乱硬规则 + §3.5 验收静态审计(CI 门禁)。
 * 运行时"无元素超出视口"断言(scrollWidth <= innerWidth)需要真实浏览器,
 * 由冒烟阶段浏览器矩阵(360/768/1024/1440)执行;本脚本做构建期可判定的红线。
 * 退出码非 0 = 存在违规。用法:php bin/check-layout.php
 */

$root = dirname(__DIR__);
$cssPath = $root . '/themes/paper/assets/paper.css';
$jsPath = $root . '/themes/paper/assets/paper.js';
$css = (string) file_get_contents($cssPath);
$js = (string) file_get_contents($jsPath);
$templates = glob($root . '/themes/paper/templates/*.php') ?: [];
$html = '';
foreach ($templates as $t) {
    $html .= (string) file_get_contents($t);
}

$fail = 0;
$pass = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
        echo "  ok    {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

echo "Paper 主题布局红线审计(08 §3.2/§3.5)\n";

// 硬规则①:grid/flex 子项 min-width:0
check(str_contains($css, 'min-width: 0'), '① grid/flex 子项 min-width:0');

// 硬规则②:正文 break-word + 代码/URL anywhere
check((bool) preg_match('/body\s*\{[^}]*overflow-wrap:\s*break-word/s', $css), '②a body overflow-wrap: break-word');
check(str_contains($css, 'overflow-wrap: anywhere'), '②b pre/u-anywhere overflow-wrap: anywhere');

// 硬规则③:宽内容包 overflow-x 滚动容器
check((bool) preg_match('/\.(code-block|table-wrap)\s*\{[^}]*overflow-x:\s*auto/', $css), '③ overflow-x 滚动容器');

// 硬规则④:图片 max-width
check((bool) preg_match('/img[^{]*\{[^}]*max-width:\s*100%/', $css), '④ img max-width:100%');

$cssCode = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
$jsCode = preg_replace('/\/\*.*?\*\//s', '', $js) ?? $js;

// 硬规则⑤:禁 100vh(只允许 100dvh)
check(strpos(str_replace('100dvh', '', $cssCode), '100vh') === false, '⑤ 无 100vh(仅 100dvh)');

// 硬规则⑥:固定元素 safe-area
check(str_contains($css, 'env(safe-area-inset-'), '⑥ env(safe-area-inset-*)');

// 硬规则⑦:hover 效果必须包在 @media (hover: hover) 内(先剥注释与 hover 媒体块)
$noComments = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
$stripped = preg_replace('/@media \(hover: hover\)\s*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $noComments) ?? $noComments;
check(!preg_match('/:\s*hover\b/', $stripped), '⑦ 所有 :hover 均在 @media (hover: hover) 内');

// 硬规则⑧:横向滚动区 scroll-snap
check(str_contains($css, 'scroll-snap-type'), '⑧ 横向滚动区 scroll-snap');

// 硬规则⑨:全局 box-sizing
check((bool) preg_match('/\*,\s*\*::before,\s*\*::after\s*\{\s*box-sizing:\s*border-box/', $css), '⑨ 全局 box-sizing: border-box');

// §3.5:视口 meta 与 rem 基座
check(str_contains($html, 'name="viewport" content="width=device-width, initial-scale=1"'), '§3.5 viewport meta');
check(str_contains($css, 'html { font-size: 100%'), '§3.5 rem 基座(html 100%)');

// §3.1:组件形态用容器查询
check(str_contains($css, '@container'), '§3.1 容器查询卡片形态(@container)');

// §6:暗色 class 策略 + 防闪烁引导脚本 + localStorage
check(str_contains($css, ':root[data-theme="dark"]'), '§6 data-theme=dark 令牌组');
check(str_contains($html, "localStorage.getItem('pb-theme')"), '§6 首帧前引导脚本');

// §3.4:禁止 JS 读 innerWidth 做布局分支
check(!str_contains($jsCode, 'innerWidth') && !str_contains($jsCode, 'document.write'), '§3.4 JS 不读 innerWidth 做布局');

// §7:图标按钮带 aria-label;img 带 alt
check(!preg_match('/<img(?![^>]*alt=)/', $html), '§7 所有 <img> 带 alt');
preg_match_all('/<button[^>]*class="[^"]*(?:theme-toggle|menu-btn)[^"]*"[^>]*>/', $html, $iconBtns);
$noLabel = array_filter($iconBtns[0], static fn (string $b): bool => !str_contains($b, 'aria-label'));
check($noLabel === [], '§7 图标按钮带 aria-label');

// reduced-motion
check(str_contains($css, 'prefers-reduced-motion'), '§2.3 prefers-reduced-motion 兜底');

echo "\n结果:{$pass} 通过";
if ($fail > 0) {
    echo ",{$fail} 违规\n";
    exit(1);
}
echo ",0 违规\n";
exit(0);
