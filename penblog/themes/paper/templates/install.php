<?php
/** @var list<array{item:string,ok:bool,required:bool,hint:string}> $envRows @var bool $blocked */
/** @var string $csrf @var array<string,string> $errors @var array<string,mixed> $old @var string $detectedUrl @var string $version */
use PenBlog\Theme\View;
$oldArr = $old;
$errArr = $errors;
$val = static fn (string $k, string $d = ''): string => View::e((string) ($oldArr[$k] ?? $d));
$err = static fn (string $k): string => isset($errArr[$k])
    ? '<span class="error">' . View::e($errArr[$k]) . '</span>' : '';
$mysqlOpen = (($oldArr['driver'] ?? 'sqlite') === 'mysql');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>try{var t=localStorage.getItem('pb-theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}</script>
<title>安装 PenBlog</title>
<link rel="stylesheet" href="/assets/paper.css">
</head>
<body>
<main class="install-wrap" id="main">
  <h1>PenBlog 安装向导</h1>
  <p class="meta">PenBlog Engine <?= View::e($version) ?> · 一屏完成,零配置 SQLite 优先</p>

  <?php if (isset($errors['#'])): ?>
    <p class="alert alert-danger" role="alert"><?= View::e($errors['#']) ?></p>
  <?php endif; ?>

  <section class="card install-card" aria-labelledby="env-h">
    <h2 id="env-h">① 环境自检</h2>
    <ul class="env-list">
      <?php foreach ($envRows as $row):
        $cls = $row['ok'] ? 'env-ok' : ($row['required'] ? 'env-fail' : 'env-warn');
        $mark = $row['ok'] ? '✓' : ($row['required'] ? '✗' : '!');
      ?>
        <li class="<?= $cls ?>"><span aria-hidden="true"><?= $mark ?></span>
          <strong><?= View::e($row['item']) ?></strong>
          <span class="hint"><?= View::e($row['hint']) ?><?= $row['required'] ? '' : '(建议)' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($blocked): ?>
      <p class="env-fail">存在未通过的必查项,请按提示修复后刷新本页重试。</p>
    <?php endif; ?>
  </section>

  <section class="card install-card" aria-labelledby="cfg-h">
    <h2 id="cfg-h">② 站点配置</h2>
    <form method="post" action="/install" novalidate>
      <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">

      <div class="field">
        <label for="site_title">站点标题</label>
        <input id="site_title" name="site_title" class="input" required maxlength="120"
               value="<?= $val('site_title', 'My PenBlog') ?>">
        <?= $err('site_title') ?>
      </div>

      <div class="field">
        <label for="site_url">站点 URL</label>
        <input id="site_url" name="site_url" class="input" required
               value="<?= $val('site_url', $detectedUrl) ?>">
        <?= $err('site_url') ?>
      </div>

      <fieldset class="field">
        <legend>数据库</legend>
        <label class="check"><input type="radio" name="driver" value="sqlite" <?= (($old['driver'] ?? 'sqlite') === 'sqlite') ? 'checked' : '' ?>> SQLite 零配置(推荐,个人博客)</label>
        <label class="check"><input type="radio" name="driver" value="mysql" <?= $mysqlOpen ? 'checked' : '' ?>> MySQL</label>
        <?= $err('driver') ?>
        <details id="mysql-box" <?= $mysqlOpen ? 'open' : '' ?>>
          <summary>MySQL 连接参数</summary>
          <div class="grid-2">
            <div class="field"><label for="m_host">主机</label><input id="m_host" name="mysql_host" class="input" value="<?= $val('mysql_host', '127.0.0.1') ?>"><?= $err('mysql_host') ?></div>
            <div class="field"><label for="m_port">端口</label><input id="m_port" name="mysql_port" class="input" inputmode="numeric" value="<?= $val('mysql_port', '3306') ?>"><?= $err('mysql_port') ?></div>
            <div class="field"><label for="m_db">库名</label><input id="m_db" name="mysql_database" class="input" value="<?= $val('mysql_database') ?>"><?= $err('mysql_database') ?></div>
            <div class="field"><label for="m_user">用户</label><input id="m_user" name="mysql_username" class="input" value="<?= $val('mysql_username') ?>"><?= $err('mysql_username') ?></div>
            <div class="field"><label for="m_pass">密码</label><input id="m_pass" name="mysql_password" type="password" class="input" autocomplete="off"><?= $err('mysql_password') ?></div>
          </div>
        </details>
      </fieldset>

      <fieldset class="field">
        <legend>管理员</legend>
        <div class="grid-2">
          <div class="field"><label for="admin_user">用户名</label>
            <input id="admin_user" name="admin_user" class="input" required value="<?= $val('admin_user', 'admin') ?>"><?= $err('admin_user') ?></div>
          <div class="field"><label for="admin_email">邮箱</label>
            <input id="admin_email" name="admin_email" type="email" class="input" required value="<?= $val('admin_email') ?>"><?= $err('admin_email') ?></div>
        </div>
        <div class="field">
          <label for="admin_pass">密码(留空自动生成 16 位强密码)</label>
          <div class="row">
            <input id="admin_pass" name="admin_pass" type="password" class="input" autocomplete="new-password" style="flex:1">
            <button type="button" class="btn btn-secondary btn-sm" id="gen-pass">生成</button>
            <button type="button" class="btn btn-secondary btn-sm" id="copy-pass">复制</button>
          </div>
          <?= $err('admin_pass') ?>
        </div>
        <label class="check"><input type="checkbox" name="auto_login" value="1" <?= isset($old['auto_login']) && !$old['auto_login'] ? '' : 'checked' ?>> 安装完成后自动登录我</label>
      </fieldset>

      <details class="field">
        <summary>高级</summary>
        <label class="check"><input type="checkbox" name="dev_mode" value="1" <?= !empty($old['dev_mode']) ? 'checked' : '' ?>> 开发模式(app.debug,详细错误输出)</label>
      </details>

      <button class="btn" type="submit" <?= $blocked ? 'disabled' : '' ?>>开始安装</button>
    </form>
  </section>

  <p class="meta">CLI 等价通道:<code>php bin/install.php</code>(同一 InstallService 内核)</p>
</main>
<script>
(function () {
  var radios = document.querySelectorAll('input[name="driver"]');
  var box = document.getElementById('mysql-box');
  radios.forEach(function (r) {
    r.addEventListener('change', function () {
      var c = document.querySelector('input[name="driver"]:checked');
      if (c && c.value === 'mysql') box.open = true;
    });
  });
  var pass = document.getElementById('admin_pass');
  document.getElementById('gen-pass').addEventListener('click', function () {
    var a = new Uint8Array(8); crypto.getRandomValues(a);
    pass.value = Array.from(a, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    pass.type = 'text';
  });
  document.getElementById('copy-pass').addEventListener('click', function () {
    if (pass.value && navigator.clipboard) navigator.clipboard.writeText(pass.value);
  });
})();
</script>
</body>
</html>
