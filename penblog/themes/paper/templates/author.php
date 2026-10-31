<?php
/** @var \PenBlog\Auth\User $user @var list<\PenBlog\Content\Post> $posts */
use PenBlog\Theme\View;
?>
<section class="prose">
  <div class="meta" style="gap: var(--space-sm); align-items: center">
    <span class="avatar" style="width:48px;height:48px" aria-hidden="true"><?= View::e(mb_substr($user->displayName(), 0, 1)) ?></span>
  </div>
  <h1><?= View::e($user->displayName()) ?></h1>
  <p class="meta">@<?= View::e($user->username()) ?></p>
  <?php if ($user->bio() !== null): ?><p><?= nl2br(View::e($user->bio())) ?></p><?php endif; ?>
  <?php if ($user->homepage() !== null): ?><p><a class="u-anywhere" href="<?= View::e($user->homepage()) ?>" rel="nofollow"><?= View::e($user->homepage()) ?></a></p><?php endif; ?>
</section>
<h2>文章</h2>
<?php if ($posts === []): ?>
  <p class="meta">暂无已发布文章。</p>
<?php else: ?>
<div class="grid">
  <?php foreach ($posts as $post): ?>
  <article class="card">
    <h3><a href="/posts/<?= rawurlencode($post->slug()) ?>"><?= View::e($post->title()) ?></a></h3>
    <div class="meta"><time><?= View::e(substr($post->publishedAt() ?? '', 0, 10)) ?></time></div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
