<?php
/** Ink 子主题首页(03 §2):覆盖 paper home,应用 layout / show_excerpt Setting。 */
/** @var list<\PenBlog\Content\Post> $posts @var array<string,string> $authors 用户名=>显示名 */
/** @var int $page @var int $total @var ?string $nextCursor @var string $siteTagline */
/** @var \PenBlog\Theme\ThemeManager|null $theme @var string $themeName */
use PenBlog\Theme\View;
$single = ($theme ?? null)?->option($themeName ?? 'ink', 'layout', 'grid') === 'list';
$showExcerpt = (bool) (($theme ?? null)?->option($themeName ?? 'ink', 'show_excerpt', true) ?? true);
?>
<section class="hero">
  <h1><?= View::e($siteTitle) ?></h1>
  <p><?= View::e($siteTagline) ?></p>
</section>
<?php if ($posts === []): ?>
  <div class="empty-state">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/></svg>
    <p>还没有已发布的文章。</p>
    <?php if (!empty($authed)): ?><a class="btn" href="/admin/dashboard">去后台发布</a><?php endif; ?>
  </div>
<?php else: ?>
<div class="grid<?= $single ? ' grid-single' : '' ?>">
  <?php foreach ($posts as $post): ?>
  <div class="card-slot">
    <article class="card">
      <h3><a href="/posts/<?= rawurlencode($post->slug()) ?>"><?= View::e($post->title()) ?></a></h3>
      <?php if ($showExcerpt && $post->excerpt() !== null): ?>
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
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<nav class="pager" aria-label="分页">
  <?php if ($page > 1): ?><a rel="prev" href="/?page=<?= $page - 1 ?>">←</a><?php endif; ?>
  <span aria-current="page"><?= $page ?></span>
  <?php if ($nextCursor !== null): ?><a rel="next" href="/?cursor=<?= rawurlencode((string) $nextCursor) ?>">→</a><?php endif; ?>
</nav>
