<?php
/** 内核保底登录页(03 §4):极简、无外部资源依赖。 */
/** @var string $siteTitle @var ?string $error */
use PenBlog\Theme\View;
?>
<section>
  <h1>登录 · <?= View::e($siteTitle ?? 'PenBlog') ?></h1>
  <?php if (!empty($error)): ?>
    <p role="alert"><?= View::e($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/admin/login">
    <p><label for="login-username">用户名或邮箱</label><br>
      <input id="login-username" name="username" type="text" autocomplete="username" required></p>
    <p><label for="login-password">密码</label><br>
      <input id="login-password" name="password" type="password" autocomplete="current-password" required></p>
    <button type="submit">登录</button>
  </form>
</section>
