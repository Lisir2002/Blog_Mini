<?php
/** @var string $content 已渲染并转义的主体 HTML */
/** @var string $title @var string $siteTitle @var string $shell site|admin */
use PenBlog\Theme\View;
$themeToggle = static fn (): string => '<button class="theme-toggle" type="button" aria-label="切换深色/浅色模式" aria-pressed="false">'
    . '<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>'
    . '<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>'
    . '</button>';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? $siteTitle ?? 'PenBlog') ?></title>
<script>try{var t=localStorage.getItem('pb-theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}</script>
<link rel="stylesheet" href="/assets/paper.css">
</head>
<body class="<?= View::e($bodyClass ?? '') ?>">
<a class="skip-link" href="#main">跳到主内容</a>
<?php if (($shell ?? 'site') === 'admin'): ?>
<div class="admin-shell">
  <aside class="admin-sidebar" aria-label="后台导航">
    <a href="/admin/dashboard" <?= ($page ?? '') === 'dashboard' ? 'aria-current="page"' : '' ?>>内容</a>
    <a href="#" aria-disabled="true">设置(M3)</a>
    <a href="/" target="_blank" rel="noopener">查看站点</a>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <strong>PenBlog 后台</strong>
      <?= $themeToggle() ?>
      <span class="meta" style="margin-inline-start:auto"><?= View::e($adminUser ?? '') ?> · <?= View::e($adminLevelTitle ?? '') ?></span>
      <form method="post" action="/admin/logout" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf ?? '') ?>">
        <button class="btn btn-secondary" type="submit">退出</button>
      </form>
    </header>
    <div class="admin-content" id="main"><?= $content ?></div>
  </div>
</div>
<?php else: ?>
<header class="site-header">
  <div class="container">
    <a class="site-logo" href="/"><?= View::e($siteTitle ?? 'PenBlog') ?></a>
    <nav class="nav-links" aria-label="主导航">
      <a href="/" <?= ($page ?? '') === 'home' ? 'aria-current="page"' : '' ?>>首页</a>
      <?php if (!empty($authed)): ?>
        <a href="/admin/dashboard" <?= ($page ?? '') === 'dashboard' ? 'aria-current="page"' : '' ?>>后台</a>
      <?php endif; ?>
      <?= $themeToggle() ?>
    </nav>
  </div>
</header>
<main id="main" class="container"><?= $content ?></main>
<footer class="site-footer">
  <div class="container">Powered by PenBlog · <?= View::e($siteTitle ?? '') ?></div>
</footer>
<?php endif; ?>
<script src="/assets/paper.js" defer></script>
</body>
</html>
