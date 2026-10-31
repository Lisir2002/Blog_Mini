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
  <p class="empty">还没有文章。可通过 <code>POST /api/v1/posts</code> 创建第一篇,M3 将提供可视化编辑器。</p>
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
        <div class="row" style="gap: var(--space-xs)">
        <?php if (!$post->isPublished()): ?>
        <form method="post" action="/admin/posts/<?= rawurlencode($post->slug()) ?>/publish">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
          <button class="btn btn-secondary btn-sm" type="submit">发布</button>
        </form>
        <?php endif; ?>
        <form method="post" action="/admin/posts/<?= rawurlencode($post->slug()) ?>/delete"
              onsubmit="return confirm('确定删除《<?= View::e($post->title()) ?>》?')">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
          <button class="btn btn-danger btn-sm" type="submit">删除</button>
        </form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
