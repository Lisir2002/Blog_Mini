<?php
/** 内核保底 layout(03 §4):主题损坏时保证前台不白屏。极简、无外部资源依赖。 */
/** @var string $content @var string $title @var string $siteTitle @var string $shell */
use PenBlog\Theme\View;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'PenBlog') ?></title>
<?= $tokensCss ?? '' ?>
<style>
*,*::before,*::after{box-sizing:border-box}
body{margin:0;font-family:system-ui,sans-serif;line-height:1.6;color:#1F2937;background:#fff;overflow-wrap:break-word}
main,.wrap{max-width:72ch;margin:0 auto;padding:1.5rem 1rem}
a{color:#4F46E5}
header,footer{border-block:1px solid #E5E7EB;padding:.75rem 1rem}
table{border-collapse:collapse}
img{max-width:100%;height:auto}
</style>
</head>
<body>
<?php if (($shell ?? 'site') === 'admin'): ?>
<header class="wrap"><a href="/admin/dashboard"><?= View::e($siteTitle ?? 'PenBlog') ?> 后台</a>
<?php if (!empty($authed)): ?> · <?= View::e($adminUser ?? '') ?>
<form method="post" action="/admin/logout" style="display:inline"><input type="hidden" name="_csrf" value="<?= View::e($csrf ?? '') ?>"><button type="submit">退出</button></form>
<?php endif; ?></header>
<main class="wrap" id="main"><?= $content ?></main>
<?php else: ?>
<header><div class="wrap"><a href="/"><?= View::e($siteTitle ?? 'PenBlog') ?></a> · <a href="/">首页</a>
<?php if (!empty($authed)): ?><a href="/admin/dashboard">后台</a><?php endif; ?></div></header>
<main class="wrap" id="main"><?= $content ?></main>
<footer><div class="wrap">Powered by PenBlog</div></footer>
<?php endif; ?>
</body>
</html>
