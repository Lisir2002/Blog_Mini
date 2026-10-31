<?php
/** @var string $siteTitle @var ?string $error */
use PenBlog\Theme\View;
?>
<section class="login-card">
  <h1>登录 · <?= View::e($siteTitle) ?></h1>
  <?php if (!empty($error)): ?>
    <p class="error" role="alert" style="color: var(--color-danger); font-size: .875rem)"><?= View::e($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/admin/login">
    <div class="field">
      <label for="login-username">用户名或邮箱</label>
      <input id="login-username" name="username" type="text" autocomplete="username" required>
    </div>
    <div class="field">
      <label for="login-password">密码</label>
      <input id="login-password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <label class="check"><input type="checkbox" name="remember" value="1"> 记住我</label>
    <button class="btn" type="submit" style="width: 100%; margin-top: var(--space-md)">登录</button>
  </form>
</section>
