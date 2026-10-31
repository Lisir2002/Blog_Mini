<?php
/** @var list<\PenBlog\Content\Post> $posts @var array<string,string> $authors 用户名=>显示名 */
/** @var int $page @var int $total @var ?string $nextCursor @var string $siteTagline */
use PenBlog\Theme\View;
?>
<section class="hero">
  <h1><?= View::e($siteTitle) ?></h1>
  <p><?= View::e($siteTagline) ?></p>
</section>
<?php if ($posts === []): ?>
  <p class="meta">还没有已发布的文章。</p>
<?php else: ?>
<div class="grid">
  <?php foreach ($posts as $post): ?>
  <article class="card">
    <h3><a href="/posts/<?= rawurlencode($post->slug()) ?>"><?= View::e($post->title()) ?></a></h3>
    <?php if ($post->excerpt() !== null): ?>
      <p class="excerpt"><?= View::e($post->excerpt()) ?></p>
    <?php endif; ?>
    <div class="meta">
      <span><?= View::e($authors[$post->author()] ?? $post->author()) ?></span>
      <span>·</span>
      <time datetime="<?= View::e($post->publishedAt() ?? '') ?>"><?= View::e(substr($post->publishedAt() ?? $post->metadata->creationTimestamp ?? '', 0, 10)) ?></time>
      <?php foreach ($post->categories() as $cat): ?>
        <span>· <?= View::e($cat) ?></span>
      <?php endforeach; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<nav class="pager" aria-label="分页">
  <?php if ($page > 1): ?><a rel="prev" href="/?page=<?= $page - 1 ?>">←</a><?php endif; ?>
  <span aria-current="page"><?= $page ?></span>
  <?php if ($nextCursor !== null): ?><a rel="next" href="/?cursor=<?= rawurlencode((string) $nextCursor) ?>">→</a><?php endif; ?>
</nav>
