<?php
/** 保底文章页(03 §4):$post(Post)/$bodyHtml(已净化)/$comments。 */
use PenBlog\Theme\View;
?>
<article>
  <h1><?= View::e($post->title()) ?></h1>
  <div class="meta"><?= View::e($post->author()) ?> · <?= View::e(substr((string) ($post->publishedAt() ?? ''), 0, 10)) ?></div>
  <div><?= $bodyHtml ?? '' ?></div>
</article>
<section id="comments">
  <h2>评论</h2>
  <?php foreach (($comments ?? []) as $c): ?>
    <p><strong><?= View::e($c['displayName'] ?? '匿名') ?></strong>:<?= View::e($c['body'] ?? '') ?></p>
  <?php endforeach; ?>
  <?php if (!empty($canComment)): ?>
  <form method="post" action="/posts/<?= rawurlencode($post->slug()) ?>/comments">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf ?? '') ?>">
    <label>评论<textarea name="body" rows="3" required></textarea></label>
    <button type="submit">发表</button>
  </form>
  <?php endif; ?>
</section>
