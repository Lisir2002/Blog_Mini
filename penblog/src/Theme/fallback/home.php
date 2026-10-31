<?php
/** 保底首页模板(03 §4):仅依赖 $posts(list<Post>)/$authors。 */
use PenBlog\Theme\View;
?>
<h1><?= View::e($siteTitle ?? 'PenBlog') ?></h1>
<?php if (empty($posts)): ?>
  <p>还没有已发布的文章。</p>
<?php else: ?>
<ul>
  <?php foreach ($posts as $post): ?>
  <li>
    <a href="/posts/<?= rawurlencode($post->slug()) ?>"><?= View::e($post->title()) ?></a>
    <time><?= View::e(substr((string) ($post->publishedAt() ?? $post->metadata->creationTimestamp ?? ''), 0, 10)) ?></time>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
