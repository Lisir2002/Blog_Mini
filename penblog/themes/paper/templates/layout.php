<?php
/** @var string $content 已渲染并转义的主体 HTML */
/** @var string $title @var string $siteTitle @var string $shell site|admin */
use PenBlog\Theme\View;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? $siteTitle ?? 'PenBlog') ?></title>
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
    </nav>
  </div>
</header>
<main id="main" class="container"><?= $content ?></main>
<footer class="site-footer">
  <div class="container">Powered by PenBlog · <?= View::e($siteTitle ?? '') ?></div>
</footer>
<?php endif; ?>
</body>
</html>
