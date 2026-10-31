<?php
/** @var \PenBlog\Content\Post $post @var ?\PenBlog\Auth\User $author @var string $bodyHtml */
/** @var list<array{id:int,body:string,status:string,createdAt:string,displayName:string,username:?string}> $comments */
/** @var bool $canComment @var string $csrf */
use PenBlog\Theme\View;
?>
<article class="prose">
  <h1><?= View::e($post->title()) ?></h1>
  <div class="meta" style="margin-block: var(--space-sm) var(--space-lg)">
    <span class="avatar" aria-hidden="true"><?= View::e(mb_substr($author?->displayName() ?? $post->author(), 0, 1)) ?></span>
    <a href="/authors/<?= rawurlencode($post->author()) ?>"><?= View::e($author?->displayName() ?? $post->author()) ?></a>
    <time datetime="<?= View::e($post->publishedAt() ?? '') ?>"><?= View::e(substr($post->publishedAt() ?? '', 0, 10)) ?></time>
    <?php foreach ($post->categories() as $cat): ?><span>· <?= View::e($cat) ?></span><?php endforeach; ?>
  </div>
  <?= $bodyHtml /* Markdown 渲染器已整体转义 */ ?>
  <?php if ($post->tags() !== []): ?>
  <div class="chip-row" aria-label="标签">
    <?php foreach ($post->tags() as $tag): ?><span class="chip"><?= View::e($tag) ?></span><?php endforeach; ?>
  </div>
  <?php endif; ?>
</article>

<section aria-label="评论" style="margin-top: var(--space-2xl)">
  <h2>评论(<?= count($comments) ?>)</h2>
  <?php if ($canComment): ?>
  <form method="post" action="/posts/<?= rawurlencode($post->slug()) ?>/comments">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <div class="field">
      <label for="comment-body">写下评论</label>
      <textarea id="comment-body" name="body" rows="5" required></textarea>
    </div>
    <button class="btn" type="submit">提交</button>
  </form>
  <?php endif; ?>
  <?php foreach ($comments as $c): ?>
  <div class="comment<?= $c['status'] === 'pending' ? ' comment-pending' : '' ?>">
    <span class="avatar" aria-hidden="true"><?= View::e(mb_substr($c['displayName'], 0, 1)) ?></span>
    <div class="body">
      <div class="meta">
        <?php if ($c['username'] !== null): ?><a href="/authors/<?= rawurlencode($c['username']) ?>"><?= View::e($c['displayName']) ?></a>
        <?php else: ?><span><?= View::e($c['displayName']) ?></span><?php endif; ?>
        <time><?= View::e(substr($c['createdAt'], 0, 16)) ?></time>
        <?php if ($c['status'] === 'pending'): ?><span class="badge badge-pending">待审核</span><?php endif; ?>
      </div>
      <p><?= nl2br(View::e($c['body'])) ?></p>
    </div>
  </div>
  <?php endforeach; ?>
</section>
