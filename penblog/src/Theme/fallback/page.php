<?php
/** 保底独立页面(03 §4):$page(PageDoc)/$bodyHtml(已净化)。 */
use PenBlog\Theme\View;
?>
<article>
  <h1><?= View::e($page->title()) ?></h1>
  <div><?= $bodyHtml ?? '' ?></div>
</article>
