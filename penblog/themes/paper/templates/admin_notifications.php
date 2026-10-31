<?php
/** @var list<array<string,mixed>> $items @var int $total @var int $page @var int $size
 *  @var string $category @var int $unread @var array<string,array{label:string,channels:list<string>}> $categories
 *  @var string $csrf @var bool $saved */
use PenBlog\Theme\View;
$tabs = array_merge([['key' => '', 'label' => '全部']], array_map(
    static fn (string $k, array $m): array => ['key' => $k, 'label' => $m['label']],
    array_keys($categories),
    $categories,
));
$catLabel = static fn (string $c): string => $categories[$c]['label'] ?? $c;
?>
<h2>通知中心</h2>
<p class="meta" style="display:flex;align-items:center;gap:var(--space-sm);flex-wrap:wrap">
  未读 <strong><?= $unread ?></strong> · 共 <?= $total ?> 条
  <span style="margin-inline-start:auto;display:flex;gap:var(--space-sm);flex-wrap:wrap">
    <a class="btn btn-secondary btn-sm" href="/admin/notifications/preferences">偏好设置</a>
    <form method="post" action="/admin/notifications/test" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
      <button class="btn btn-secondary btn-sm" type="submit">发测试通知</button>
    </form>
    <?php if ($unread > 0): ?>
    <form method="post" action="/admin/notifications/read-all" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
      <button class="btn btn-sm" type="submit">全部已读</button>
    </form>
    <?php endif; ?>
  </span>
</p>
<?php if ($saved): ?><p class="badge badge-published">操作已保存</p><?php endif; ?>

<nav class="chip-row" aria-label="通知分类">
  <?php foreach ($tabs as $tab): ?>
    <a class="chip<?= $category === $tab['key'] ? ' chip-active' : '' ?>"
       href="/admin/notifications<?= $tab['key'] !== '' ? '?category=' . rawurlencode($tab['key']) : '' ?>"
       <?= $category === $tab['key'] ? 'aria-current="true"' : '' ?>><?= View::e($tab['label']) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($items === []): ?>
  <div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
    <p>这里空空如也。新评论、发布动态都会出现在这里。</p>
  </div>
<?php else: ?>
<ul class="notif-list">
  <?php foreach ($items as $row):
    $isUnread = $row['read_at'] === null;
    $link = (string) ($row['link'] ?? '') !== '' ? (string) $row['link'] : '/admin/notifications';
  ?>
  <li class="notif-row<?= $isUnread ? ' unread' : '' ?>">
    <span class="notif-dot" aria-hidden="true"></span>
    <div class="notif-body">
      <a class="notif-title" href="<?= View::e($link) ?>" data-notif-read="<?= (int) $row['id'] ?>">
        <?= View::e($row['title'] !== '' ? $row['title'] : '(' . $catLabel((string) $row['category']) . '通知)') ?>
      </a>
      <div class="notif-meta">
        <span class="badge<?= $row['importance'] === 'critical' ? ' badge-danger' : '' ?>"><?= View::e($catLabel((string) $row['category'])) ?></span>
        <time><?= View::e(View::ago((string) $row['created_at'])) ?></time>
        <?php if ($row['status'] === 'partial' || $row['status'] === 'failed'): ?>
          <span class="badge badge-danger">投递失败</span>
        <?php endif; ?>
      </div>
    </div>
    <form method="post" action="/admin/notifications/<?= (int) $row['id'] ?>/archive" class="notif-actions">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
      <button class="btn btn-secondary btn-sm" type="submit" aria-label="归档《<?= View::e($row['title']) ?>》">归档</button>
    </form>
  </li>
  <?php endforeach; ?>
</ul>
<?php $pages = max(1, (int) ceil($total / $size)); if ($pages > 1): ?>
<nav class="pager" aria-label="分页">
  <?php $qs = static fn (int $p): string => '/admin/notifications?' . http_build_query(array_filter(['category' => $category !== '' ? $category : null, 'page' => $p > 1 ? $p : null])); ?>
  <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= View::e($qs($page - 1)) ?>">上一页</a><?php endif; ?>
  <span class="meta">第 <?= $page ?> / <?= $pages ?> 页</span>
  <?php if ($page < $pages): ?><a class="btn btn-secondary btn-sm" href="<?= View::e($qs($page + 1)) ?>">下一页</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
