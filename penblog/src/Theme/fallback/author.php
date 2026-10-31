<?php
/** 保底作者页(03 §4):$user(User)/$posts(list<Post>)。 */
use PenBlog\Theme\View;
?>
<h1><?= View::e($user->displayName()) ?></h1>
<?php if (empty($posts)): ?>
  <p>该作者还没有已发布的文章。</p>
<?php else: ?>
<ul>
  <?php foreach ($posts as $post): ?>
  <li><a href="/posts/<?= rawurlencode($post->slug()) ?>"><?= View::e($post->title()) ?></a></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
