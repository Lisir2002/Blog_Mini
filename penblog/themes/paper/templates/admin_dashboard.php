<?php
/** @var list<\PenBlog\Content\Post> $posts @var string $csrf @var int $total */
use PenBlog\Theme\View;
$badge = static fn (string $s): string => match ($s) {
    'published' => 'badge-published',
    'pending' => 'badge-pending',
    default => 'badge-draft',
};
?>
<h2>内容 · 文章</h2>
<p class="meta">共 <?= $total ?> 篇</p>
<?php if ($posts === []): ?>
  <div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/></svg>
    <p>还没有文章。可通过 <code>POST /api/v1/posts</code> 创建第一篇,可视化编辑器在后续里程碑提供。</p>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="admin-table">
  <thead>
    <tr><th scope="col">标题</th><th scope="col">状态</th><th scope="col">作者</th><th scope="col">日期</th><th scope="col">操作</th></tr>
  </thead>
  <tbody>
  <?php foreach ($posts as $post): ?>
    <tr>
      <td class="title-cell">
        <?php if ($post->isPublished()): ?>
          <a href="/posts/<?= rawurlencode($post->slug()) ?>"><?= View::e($post->title()) ?></a>
        <?php else: ?>
          <strong><?= View::e($post->title()) ?></strong>
        <?php endif; ?>
        <div class="sub u-anywhere"><?= View::e($post->slug()) ?></div>
      </td>
      <td><span class="badge <?= $badge($post->status()) ?>"><?= View::e($post->status()) ?></span></td>
      <td><?= View::e($post->author()) ?></td>
      <td><time><?= View::e(substr($post->publishedAt() ?? $post->metadata->creationTimestamp ?? '', 0, 10)) ?></time></td>
      <td>
        <div class="menu" data-menu>
          <button class="menu-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-label="《<?= View::e($post->title()) ?>》的操作">⋯</button>
          <div class="menu-panel" role="menu">
            <?php if (!$post->isPublished()): ?>
            <form method="post" action="/admin/posts/<?= rawurlencode($post->slug()) ?>/publish" role="none">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
              <button type="submit" role="menuitem">发布</button>
            </form>
            <?php endif; ?>
            <form method="post" action="/admin/posts/<?= rawurlencode($post->slug()) ?>/delete" role="none"
                  data-confirm="<?= View::e($post->title()) ?>">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
              <button type="submit" role="menuitem" class="danger">删除</button>
            </form>
          </div>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<dialog class="modal" id="confirm-modal" aria-labelledby="confirm-h">
  <div class="modal-body">
    <h3 id="confirm-h">确认删除</h3>
    <p>确定删除《<span class="modal-target"></span>》?此操作不可撤销。</p>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary modal-cancel">取消</button>
      <button type="button" class="btn btn-danger modal-confirm">删除</button>
    </div>
  </div>
</dialog>
<?php endif; ?>
