<?php
/** @var list<array<string, mixed>> $plugins @var array<string, string> $errors
 *  @var list<array{plugin:string, section:\PenBlog\Plugin\SettingSection, values:array<string,mixed>}> $sections
 *  @var ?string $notice @var ?string $failure @var string $csrf */
use PenBlog\Theme\View;
$badge = static fn (string $s): string => match ($s) {
    'active' => 'badge-published',
    'failed' => 'badge-pending',
    'none' => 'badge-draft',
    default => 'badge-draft',
};
$actionLabel = static fn (string $action, string $state): string => match (true) {
    $action === 'install' => '安装',
    $action === 'activate' => $state === 'failed' ? '重试激活' : '启用',
    $action === 'deactivate' => '停用',
    default => '卸载',
};
?>
<h2>插件</h2>
<p class="meta">manifest + 生命周期 + 扩展点注册(04)。禁用全部插件后系统仍可用。</p>
<?php if ($notice !== null): ?><p class="badge badge-published" role="status"><?= View::e($notice) ?></p><?php endif; ?>
<?php if ($failure !== null): ?><p class="badge badge-pending" role="alert"><?= View::e($failure) ?></p><?php endif; ?>

<?php if ($plugins === [] && $errors === []): ?>
  <div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
    <p>plugins/ 目录下没有发现任何插件。放置包含 plugin.json 的目录后刷新本页。</p>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="admin-table">
  <thead>
    <tr><th scope="col">插件</th><th scope="col">状态</th><th scope="col">版本</th><th scope="col">依赖</th><th scope="col">操作</th></tr>
  </thead>
  <tbody>
  <?php foreach ($plugins as $p): ?>
    <?php $state = (string) $p['state']; ?>
    <tr>
      <td class="title-cell">
        <strong><?= View::e($p['name']) ?></strong>
        <div class="sub u-anywhere"><?= View::e((string) $p['description']) ?></div>
        <?php if ($state === 'failed' && !empty($p['error'])): ?>
          <div class="sub u-anywhere" role="alert">激活失败:<?= View::e((string) $p['error']) ?></div>
        <?php endif; ?>
        <?php if (!empty($p['permissions'])): ?>
          <div class="sub">权限:<?= View::e(implode(', ', (array) $p['permissions'])) ?></div>
        <?php endif; ?>
      </td>
      <td><span class="badge <?= $badge($state) ?>"><?= View::e($state) ?></span></td>
      <td><time><?= View::e((string) $p['version']) ?></time></td>
      <td class="sub"><?= View::e(implode(', ', (array) ($p['requires']['plugins'] ?? []))) ?: '—' ?></td>
      <td>
        <div style="display:flex;gap:var(--space-sm);flex-wrap:wrap">
        <?php if ($state === 'none'): ?>
          <form method="post" action="/admin/plugins/<?= rawurlencode($p['name']) ?>/install" role="none">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <button class="btn btn-sm" type="submit">安装</button>
          </form>
        <?php elseif ($state === 'active'): ?>
          <form method="post" action="/admin/plugins/<?= rawurlencode($p['name']) ?>/deactivate" role="none">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <button class="btn btn-sm btn-secondary" type="submit">停用</button>
          </form>
        <?php else: ?>
          <form method="post" action="/admin/plugins/<?= rawurlencode($p['name']) ?>/activate" role="none">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <button class="btn btn-sm" type="submit"><?= $actionLabel('activate', $state) ?></button>
          </form>
          <form method="post" action="/admin/plugins/<?= rawurlencode($p['name']) ?>/uninstall" role="none">
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

<?php if ($sections !== []): ?>
<h3>插件设置(SettingSection 自动出现)</h3>
<?php foreach ($sections as $s): ?>
  <form method="post" action="/admin/plugins/<?= rawurlencode($s['plugin']) ?>/settings/<?= rawurlencode($s['section']->id) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <fieldset>
      <legend><?= View::e($s['section']->title) ?> · <?= View::e($s['plugin']) ?></legend>
      <?php foreach ($s['section']->fields as $field): ?>
        <?php $val = $s['values'][$field['name']] ?? ($field['default'] ?? ''); ?>
        <?php if ($field['type'] === 'checkbox'): ?>
          <label><input type="checkbox" name="<?= View::e($field['name']) ?>" value="1" <?= $val ? 'checked' : '' ?>> <?= View::e((string) $field['label']) ?></label>
        <?php elseif ($field['type'] === 'textarea'): ?>
          <label><?= View::e((string) $field['label']) ?><textarea name="<?= View::e($field['name']) ?>" rows="3"><?= View::e((string) $val) ?></textarea></label>
        <?php else: ?>
          <label><?= View::e((string) $field['label']) ?><input type="<?= $field['type'] === 'number' ? 'number' : 'text' ?>" name="<?= View::e($field['name']) ?>" value="<?= View::e((string) $val) ?>"></label>
        <?php endif; ?>
      <?php endforeach; ?>
      <button class="btn btn-sm" type="submit">保存</button>
    </fieldset>
  </form>
<?php endforeach; ?>
<?php endif; ?>
