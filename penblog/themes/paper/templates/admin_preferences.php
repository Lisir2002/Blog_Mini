<?php
/** @var list<array<string,mixed>> $cards @var list<string> $channels @var string $webhookUrl
 *  @var string $csrf @var bool $saved */
use PenBlog\Theme\View;
$chLabel = static fn (string $c): string => match ($c) {
    'in_app' => '站内信',
    'email' => '邮件',
    'webhook' => 'Webhook',
    default => $c,
};
?>
<h2>通知偏好</h2>
<p class="meta">按分类配置接收渠道与静默时段;静默期内仅保留站内信(重要通知除外)。</p>
<?php if ($saved): ?><p class="badge badge-published">已保存</p><?php endif; ?>

<div class="grid">
  <?php foreach ($cards as $card): ?>
  <form class="card" method="post" action="/admin/notifications/preferences">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <input type="hidden" name="category" value="<?= View::e($card['category']) ?>">
    <h3 class="card-title"><?= View::e($card['label']) ?></h3>
    <label class="field">
      <input type="checkbox" name="use_default" value="1" <?= $card['useDefault'] ? 'checked' : '' ?>>
      使用系统默认渠道(<?= View::e(implode(' / ', array_map($chLabel, $card['defaultChannels']))) ?>)
    </label>
    <div class="chip-row" role="group" aria-label="<?= View::e($card['label']) ?>渠道">
      <?php foreach ($channels as $ch): ?>
        <label class="chip">
          <input type="checkbox" name="channels[]" value="<?= View::e($ch) ?>"
                 <?= in_array($ch, $card['channels'], true) ? 'checked' : '' ?>>
          <?= View::e($chLabel($ch)) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="field-row">
      <label class="field">静默开始
        <input class="input" type="time" name="quiet_start" value="<?= View::e($card['quietStart']) ?>">
      </label>
      <label class="field">静默结束
        <input class="input" type="time" name="quiet_end" value="<?= View::e($card['quietEnd']) ?>">
      </label>
      <label class="field">时区
        <input class="input" type="text" name="quiet_tz" placeholder="Asia/Shanghai" value="<?= View::e($card['quietTz']) ?>">
      </label>
    </div>
    <p class="meta">静默时段支持跨零点(如 22:00 → 07:00);留空表示不静默。</p>
    <button class="btn btn-sm" type="submit">保存</button>
  </form>
  <?php endforeach; ?>

  <form class="card" method="post" action="/admin/notifications/webhook">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <h3 class="card-title">Webhook 端点</h3>
    <label class="field">接收 URL(http/https,留空清除)
      <input class="input" type="url" name="webhook_url" placeholder="https://example.com/hook" value="<?= View::e($webhookUrl) ?>">
    </label>
    <label class="field">签名密钥(留空不修改)
      <input class="input" type="password" name="webhook_secret" autocomplete="off">
    </label>
    <p class="meta">请求头携带 <code>X-PenBlog-Signature: sha256=HMAC(body, 密钥)</code>。</p>
    <button class="btn btn-sm" type="submit">保存 Webhook</button>
  </form>
</div>
