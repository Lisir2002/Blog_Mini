<?php
/** @var \PenBlog\Content\Page $page @var string $bodyHtml */
use PenBlog\Theme\View;
?>
<article class="prose">
  <h1><?= View::e($page->title()) ?></h1>
  <?= $bodyHtml ?>
</article>
