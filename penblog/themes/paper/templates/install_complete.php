<?php
/** @var string $siteTitle @var string $adminUser @var string $adminPass @var bool $passGenerated */
/** @var string $configPath @var list<string> $migrations @var bool $autoLogin @var string $version */
use PenBlog\Theme\View;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>try{var t=localStorage.getItem('pb-theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}</script>
<title>安装完成 · PenBlog</title>
<link rel="stylesheet" href="/assets/paper.css">
</head>
<body>
<main class="install-wrap" id="main">
  <h1>安装完成</h1>
  <section class="card install-card">
    <h2>站点信息</h2>
    <dl class="summary">
      <dt>站点标题</dt><dd><?= View::e($siteTitle) ?></dd>
      <dt>管理员</dt><dd><?= View::e($adminUser) ?></dd>
      <?php if ($passGenerated): ?>
        <dt>密码(仅此展示一次)</dt>
        <dd><code id="done-pass"><?= View::e($adminPass) ?></code>
          <button type="button" class="btn btn-secondary btn-sm" id="copy-pass">复制</button></dd>
      <?php endif; ?>
      <dt>迁移</dt><dd><?= View::e(implode(' → ', $migrations) ?: '已是最新') ?></dd>
      <dt>配置文件</dt><dd><code class="u-anywhere"><?= View::e($configPath) ?></code></dd>
    </dl>
    <?php if ($passGenerated): ?>
      <p class="env-warn">请立即保存密码;此后不可恢复查看,只能通过数据库重置。</p>
    <?php endif; ?>
    <div class="row" style="gap: var(--space-sm)">
      <?php if ($autoLogin): ?>
        <a class="btn" href="/admin/dashboard">进入后台</a>
      <?php else: ?>
        <a class="btn" href="/admin/login">登录后台</a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="/">查看站点</a>
    </div>
  </section>
  <section class="card install-card">
    <h2>下一步</h2>
    <ol class="next-steps">
      <li>在后台发布第一篇文章(安装已内置示例文章 "Hello PenBlog",可一键删除)。</li>
      <li>到 <code>/api/v1/health</code> 验证 API;OpenAPI 与插件市场在后续里程碑提供。</li>
      <li>如需重装:先手动删除 <code>config/config.php</code>,再执行 <code>php bin/install.php --force</code>。</li>
    </ol>
  </section>
  <p class="meta">PenBlog Engine <?= View::e($version) ?></p>
</main>
<script>
document.getElementById('copy-pass')?.addEventListener('click', function () {
  var t = document.getElementById('done-pass')?.textContent || '';
  if (t && navigator.clipboard) navigator.clipboard.writeText(t);
});
</script>
</body>
</html>
