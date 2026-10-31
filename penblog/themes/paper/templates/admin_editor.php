<?php
/** Markdown 编辑器(11 §3):textarea + 工具栏 + 服务端权威分屏预览 + 本地草稿。 */
/** @var ?\PenBlog\Content\Post $post @var array<string, string> $values */
/** @var ?int $resourceVersion @var string $action @var string $draftKey @var string $heading */
/** @var ?string $error @var bool $conflict @var bool $saved @var string $csrf */
use PenBlog\Theme\View;
?>
<div class="editor-page"
     data-preview-url="/admin/preview"
     data-draft-key="<?= View::e($draftKey) ?>"
     data-csrf="<?= View::e($csrf) ?>">

  <?php if (!empty($saved)): ?>
    <div class="alert alert-success" role="status">已保存<?= $post !== null && $post->isPublished() ? ',文章已发布' : '' ?>。</div>
  <?php endif; ?>
  <?php if ($conflict): ?>
    <div class="alert alert-danger" role="alert">
      <strong>保存冲突:</strong>存在更新版本,本地副本已保存,请复制后重试——
      你的输入已完整保留在下方,建议先复制正文备份,再 <a href="<?= View::e($action) ?>">重新加载最新版本</a> 后合并修改。
    </div>
  <?php elseif (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= View::e($error) ?></div>
  <?php endif; ?>

  <form id="editor-form" method="post" action="<?= View::e($action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <?php if ($resourceVersion !== null): ?>
      <input type="hidden" name="resourceVersion" value="<?= (int) $resourceVersion ?>">
    <?php endif; ?>

    <div class="editor-grid">
      <div class="editor-main">
        <div class="editor-toolbar" role="toolbar" aria-label="Markdown 工具栏">
          <button type="button" class="tb-btn" data-md="bold" title="加粗 (Ctrl+B)" aria-label="加粗"><strong>B</strong></button>
          <button type="button" class="tb-btn" data-md="italic" title="斜体 (Ctrl+I)" aria-label="斜体"><em>I</em></button>
          <button type="button" class="tb-btn" data-md="strike" title="删除线 (Ctrl+D)" aria-label="删除线"><s>S</s></button>
          <button type="button" class="tb-btn" data-md="heading" title="标题(循环 H1-H3)" aria-label="标题循环 H1 至 H3">H</button>
          <button type="button" class="tb-btn" data-md="list" title="无序列表" aria-label="插入无序列表">•≡</button>
          <button type="button" class="tb-btn" data-md="olist" title="有序列表" aria-label="插入有序列表">1≡</button>
          <button type="button" class="tb-btn" data-md="task" title="任务项" aria-label="插入任务项">☑</button>
          <button type="button" class="tb-btn" data-md="quote" title="引用" aria-label="插入引用">❝</button>
          <button type="button" class="tb-btn" data-md="code" title="行内代码" aria-label="行内代码">&lt;/&gt;</button>
          <button type="button" class="tb-btn" data-md="block" title="代码块" aria-label="插入代码块">{ }</button>
          <button type="button" class="tb-btn" data-md="link" title="链接 (Ctrl+K)" aria-label="插入链接">🔗</button>
          <button type="button" class="tb-btn" data-md="image" title="图片(外链 URL)" aria-label="插入图片">🖼</button>
          <button type="button" class="tb-btn" data-md="table" title="表格" aria-label="插入表格">▦</button>
          <button type="button" class="tb-btn" data-md="hr" title="分割线" aria-label="插入分割线">—</button>
          <button type="button" class="tb-btn" data-md="footnote" title="脚注" aria-label="插入脚注">¹</button>
          <button type="button" class="tb-btn" data-md="break" title="摘要分隔符" aria-label="插入摘要分隔符">✂</button>
          <span class="tb-spacer" aria-hidden="true"></span>
          <span class="meta" id="md-count" aria-live="off">中文 0 字 · 英文 0 词</span>
          <button type="button" class="btn btn-secondary btn-sm" id="preview-toggle" aria-pressed="false" title="预览 (Ctrl+Shift+P)">预览</button>
        </div>
        <textarea id="md-body" name="bodyMd" class="md-textarea" rows="18" spellcheck="false"
                  placeholder="用 Markdown 写作…支持 GFM 表格/任务列表/脚注,原始 HTML 将被转义。"><?= View::e($values['bodyMd']) ?></textarea>
        <div id="md-preview" class="prose editor-preview" aria-label="渲染预览"></div>
      </div>

      <aside class="editor-side" aria-label="文章设置">
        <div class="field">
          <label for="f-title">标题 *</label>
          <input id="f-title" name="title" type="text" required maxlength="200" value="<?= View::e($values['title']) ?>">
        </div>
        <div class="field">
          <label for="f-status">状态</label>
          <select id="f-status" name="status">
            <?php foreach (['draft' => '草稿', 'published' => '发布', 'archived' => '归档'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= $values['status'] === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="f-slug">Slug(留空自动生成)</label>
          <input id="f-slug" name="slug" type="text" maxlength="190" value="<?= View::e($values['slug']) ?>" dir="ltr">
        </div>
        <div class="field">
          <label for="f-excerpt">摘要(可留空,正文可用 &lt;!--break--&gt; 分隔)</label>
          <textarea id="f-excerpt" name="excerpt" rows="3" maxlength="500"><?= View::e($values['excerpt']) ?></textarea>
        </div>
        <div class="field">
          <label for="f-categories">分类(逗号分隔)</label>
          <input id="f-categories" name="categories" type="text" value="<?= View::e($values['categories']) ?>">
        </div>
        <div class="field">
          <label for="f-tags">标签(逗号分隔)</label>
          <input id="f-tags" name="tags" type="text" value="<?= View::e($values['tags']) ?>">
        </div>
        <button class="btn btn-primary" type="submit" id="save-btn">保存</button>
        <p class="meta" id="draft-status" aria-live="polite"></p>
      </aside>
    </div>
  </form>

  <div class="draft-banner alert" id="draft-banner" hidden>
    检测到本地未保存草稿(<span class="draft-time"></span>)。
    <button type="button" class="btn btn-sm" id="draft-restore">恢复</button>
    <button type="button" class="btn btn-secondary btn-sm" id="draft-discard">丢弃</button>
  </div>
</div>
<script src="/assets/editor.js" defer></script>
