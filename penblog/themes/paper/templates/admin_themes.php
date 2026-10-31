<?php
/** @var list<array<string, mixed>> $themes @var array<string, string> $errors
 *  @var string $active @var list<array<string, mixed>> $schema @var array<string, mixed> $values
 *  @var ?string $notice @var ?string $failure @var string $csrf */
use PenBlog\Theme\View;
?>
<h2>主题</h2>
<p class="meta">theme.json manifest + 继承链 + 设计令牌(03)。停用/卸载当前主题前需先切换到其他主题。</p>
<?php if ($notice !== null): ?><p class="badge badge-published" role="status"><?= View::e($notice) ?></p><?php endif; ?>
<?php if ($failure !== null): ?><p class="badge badge-pending" role="alert"><?= View::e($failure) ?></p><?php endif; ?>

<?php if ($themes === [] && $errors === []): ?>
  <div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/></svg>
    <p>themes/ 目录下没有发现任何主题。放置包含 theme.json 的目录后刷新本页。</p>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="admin-table">
  <thead>
    <tr><th scope="col">主题</th><th scope="col">状态</th><th scope="col">版本</th><th scope="col">继承</th><th scope="col">操作</th></tr>
  </thead>
  <tbody>
  <?php foreach ($themes as $t): ?>
    <?php $state = (string) $t['state']; ?>
    <tr>
      <td class="title-cell">
        <strong><?= View::e((string) ($t['displayName'] ?: $t['name'])) ?></strong> <span class="sub"><?= View::e($t['name']) ?></span>
        <div class="sub u-anywhere"><?= View::e((string) $t['description']) ?></div>
        <?php if (!empty($t['tags'])): ?><div class="sub">标签:<?= View::e(implode(', ', (array) $t['tags'])) ?></div><?php endif; ?>
      </td>
      <td><span class="badge <?= $state === 'active' ? 'badge-published' : 'badge-draft' ?>"><?= $state === 'active' ? '当前主题' : '未启用' ?></span></td>
      <td><time><?= View::e((string) $t['version']) ?></time></td>
      <td class="sub"><?= View::e((string) ($t['extends'] ?? '')) ?: '—' ?></td>
      <td>
        <div style="display:flex;gap:var(--space-sm);flex-wrap:wrap">
        <?php if ($state === 'active'): ?>
          <span class="badge badge-published" role="status">使用中</span>
        <?php else: ?>
          <form method="post" action="/admin/themes/<?= rawurlencode($t['name']) ?>/activate" role="none">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <button class="btn btn-sm" type="submit">激活</button>
          </form>
          <form method="post" action="/admin/themes/<?= rawurlencode($t['name']) ?>/uninstall" role="none">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <button class="btn btn-sm btn-danger" type="submit">卸载</button>
          </form>
        <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php foreach ($errors as $dir => $msg): ?>
  <p class="badge badge-pending" role="alert">发现失败 <?= View::e($dir) ?>:<?= View::e($msg) ?></p>
<?php endforeach; ?>

<?php if ($schema !== []): ?>
<h3><?= View::e($active) ?> 主题设置(声明式,自动出现)</h3>
<form method="post" action="/admin/themes/<?= rawurlencode($active) ?>/settings">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
  <fieldset>
    <legend>设置 · <?= View::e($active) ?></legend>
    <?php foreach ($schema as $field): ?>
      <?php $name = (string) $field['name']; $val = $values[$name] ?? ($field['default'] ?? ''); ?>
      <?php if ($field['type'] === 'boolean'): ?>
        <label><input type="checkbox" name="<?= View::e($name) ?>" value="1" <?= $val ? 'checked' : '' ?>> <?= View::e((string) $field['label']) ?></label>
      <?php elseif ($field['type'] === 'textarea'): ?>
        <label><?= View::e((string) $field['label']) ?><textarea name="<?= View::e($name) ?>" rows="3"><?= View::e((string) $val) ?></textarea></label>
      <?php elseif ($field['type'] === 'radio'): ?>
        <fieldset><legend><?= View::e((string) $field['label']) ?></legend>
          <?php foreach ((array) $field['options'] as $opt): ?>
            <label class="check"><input type="radio" name="<?= View::e($name) ?>" value="<?= View::e((string) $opt) ?>" <?= (string) $val === (string) $opt ? 'checked' : '' ?>> <?= View::e((string) $opt) ?></label>
          <?php endforeach; ?>
        </fieldset>
      <?php else: ?>
        <label><?= View::e((string) $field['label']) ?><input type="<?= $field['type'] === 'number' ? 'number' : 'text' ?>" name="<?= View::e($name) ?>"
          value="<?= View::e((string) $val) ?>"
          <?= isset($field['min']) ? 'min="' . View::e((string) $field['min']) . '"' : '' ?>
          <?= isset($field['max']) ? 'max="' . View::e((string) $field['max']) . '"' : '' ?>></label>
      <?php endif; ?>
    <?php endforeach; ?>
    <button class="btn btn-sm" type="submit">保存</button>
  </fieldset>
</form>
<?php endif; ?>
