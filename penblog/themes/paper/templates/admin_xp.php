<?php
/** @var array{enabled:bool, rules:list<array<string, mixed>>, thresholds:array<string, int>} $settings
 *  @var string $rulesJson @var list<string> $metrics
 *  @var ?string $notice @var ?string $failure @var string $csrf */
use PenBlog\Theme\View;
$metricLabels = [
    'reading_minutes' => '阅读分钟',
    'days_visited' => '访问天数',
    'comments_posted' => '发表评论',
    'comments_received_like' => '评论获赞',
    'posts_published' => '发布文章',
];
?>
<h2>经验系统</h2>
<p class="meta">开启后,用户活跃度(评论、发文等指标)按规则累计经验,达到阈值自动晋升等级(只升不降;<strong>L0 永不自动授予</strong>,自动晋升上限 L1)。当前内核仅接入"发表评论 / 发布文章"两类事件,阅读时长与访问天数需前端埋点配合。</p>
<?php if ($notice !== null): ?><p class="badge badge-published" role="status"><?= View::e($notice) ?></p><?php endif; ?>
<?php if ($failure !== null): ?><p class="badge badge-pending" role="alert"><?= View::e($failure) ?></p><?php endif; ?>

<form method="post" action="/admin/xp">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
  <label style="display:flex;align-items:center;gap:var(--space-sm)">
    <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
    启用经验系统(关闭时不记账、不晋升)
  </label>

  <h3>晋升阈值(累计经验)</h3>
  <div style="display:flex;gap:var(--space-lg);flex-wrap:wrap">
    <label>升到 L1(编辑)<input type="number" name="threshold_1" min="0" step="1" value="<?= (int) ($settings['thresholds']['1'] ?? 0) ?>"></label>
    <label>升到 L2(作者)<input type="number" name="threshold_2" min="0" step="1" value="<?= (int) ($settings['thresholds']['2'] ?? 0) ?>"></label>
  </div>
  <p class="meta">建议 L2 阈值低于 L1(先升 L2 再升 L1);注册默认 L3。</p>

  <h3>经验规则(JSON)</h3>
  <p class="meta">指标白名单:<?= View::e(implode(' / ', array_map(static fn (string $m): string => ($metricLabels[$m] ?? $m) . '(' . $m . ')', $metrics))) ?>。
    每条规则含 <code>metric</code>、<code>xp_per_unit</code>(0-1000)、可选 <code>per_day_cap</code>(当日累计量上限,防刷)。留空则使用默认规则。</p>
  <textarea name="rules" rows="14" spellcheck="false" aria-label="经验规则 JSON" style="width:100%;font-family:monospace"><?= View::e($rulesJson) ?></textarea>

  <button class="btn" type="submit">保存经验设置</button>
</form>
