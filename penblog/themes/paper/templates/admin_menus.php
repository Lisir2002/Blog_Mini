<?php
/** @var list<array{location:string, items:list<array<string, mixed>>, resourceVersion:int}> $menus
 *  @var ?string $edit @var bool $editing @var list<array{label:string, type:string, target:string, sort:int}> $rows
 *  @var list<string> $types @var array<string, string> $typeLabels
 *  @var ?string $notice @var ?string $failure @var string $csrf */
use PenBlog\Theme\View;
$targetHint = static fn (string $type): string => match ($type) {
    'post' => '文章 slug',
    'page' => '页面 slug',
    'category' => '分类名称',
    'author' => '作者用户名',
    default => '以 / 或 http(s):// 开头',
};
?>
<h2>菜单</h2>
<p class="meta">菜单位置由主题约定(如 <code>primary</code> 主导航、<code>footer</code> 页脚)。条目引用对象,目标失效时前台自动隐藏;主导航未配置时回退"首页"。</p>
<?php if ($notice !== null): ?><p class="badge badge-published" role="status"><?= View::e($notice) ?></p><?php endif; ?>
<?php if ($failure !== null): ?><p class="badge badge-pending" role="alert"><?= View::e($failure) ?></p><?php endif; ?>

<?php if ($menus === []): ?>
  <div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
    <p>还没有菜单。用下方表单创建第一个菜单位置(如 primary)。</p>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="admin-table">
  <thead>
    <tr><th scope="col">位置</th><th scope="col">条目数</th><th scope="col">版本</th><th scope="col">操作</th></tr>
  </thead>
  <tbody>
  <?php foreach ($menus as $m): ?>
    <tr>
      <td><strong><?= View::e($m['location']) ?></strong>
        <?php if ($m['location'] === 'primary'): ?><span class="badge badge-published">主导航</span><?php endif; ?>
        <?php if ($m['location'] === 'footer'): ?><span class="badge badge-draft">页脚</span><?php endif; ?>
      </td>
      <td><?= count($m['items']) ?></td>
      <td class="sub">v<?= (int) $m['resourceVersion'] ?></td>
      <td>
        <div style="display:flex;gap:var(--space-sm);flex-wrap:wrap">
          <a class="btn btn-sm" href="/admin/menus?edit=<?= rawurlencode($m['location']) ?>">编辑</a>
          <form method="post" action="/admin/menus/<?= rawurlencode($m['location']) ?>/delete" role="none">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
            <button class="btn btn-sm btn-danger" type="submit">删除</button>
          </form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h3><?= $editing ? '编辑菜单:' . View::e((string) $edit) : '新建菜单' ?></h3>
<?php $formLocation = $editing ? (string) $edit : ''; ?>
<form method="post" action="<?= $editing ? '/admin/menus/' . rawurlencode($formLocation) : '/admin/menus' ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
  <?php if ($editing): ?>
    <p class="meta">位置:<strong><?= View::e($formLocation) ?></strong>(不可更改)</p>
  <?php else: ?>
    <label>菜单位置(小写字母开头,可含 -/_)<input type="text" name="location" required maxlength="32" placeholder="primary"></label>
  <?php endif; ?>
  <div class="table-wrap">
  <table class="admin-table">
    <thead>
      <tr><th scope="col">标签</th><th scope="col">类型</th><th scope="col">目标</th><th scope="col">排序</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $row): ?>
      <tr>
        <td><input type="text" name="items[<?= $i ?>][label]" maxlength="64" value="<?= View::e($row['label']) ?>" aria-label="第 <?= $i + 1 ?> 行标签" placeholder="留空则忽略本行"></td>
        <td><select name="items[<?= $i ?>][type]" aria-label="第 <?= $i + 1 ?> 行类型">
            <?php foreach ($types as $t): ?>
              <option value="<?= View::e($t) ?>" <?= $row['type'] === $t ? 'selected' : '' ?>><?= View::e($typeLabels[$t] ?? $t) ?></option>
            <?php endforeach; ?>
          </select></td>
        <td><input type="text" name="items[<?= $i ?>][target]" maxlength="255" value="<?= View::e($row['target']) ?>" aria-label="第 <?= $i + 1 ?> 行目标" placeholder="<?= View::e($targetHint($row['type'])) ?>"></td>
        <td><input type="number" name="items[<?= $i ?>][sort]" value="<?= (int) $row['sort'] ?>" step="1" aria-label="第 <?= $i + 1 ?> 行排序"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="meta">排序小的在前;同排序按表单行顺序。最多 30 条。</p>
  <button class="btn" type="submit">保存菜单</button>
  <?php if ($editing): ?><a class="btn btn-secondary" href="/admin/menus">返回列表</a><?php endif; ?>
</form>
