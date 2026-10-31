<?php

declare(strict_types=1);

namespace PenBlog\Notification;

/**
 * 05 §5 渲染层:模板白名单 + {{key}} 占位符替换。
 * in_app 输出 HTML(先转义后 nl2br),email/webhook 输出纯文本。
 * 未知模板键返回 null → 上层记 render_failed。
 */
final class NotificationTemplates
{
    /** @var array<string, array{title: string, body: string, link: string}> */
    private const TEMPLATES = [
        'comment.new' => [
            'title' => '新评论 · {{post_title}}',
            'body' => '《{{post_title}}》收到 {{author_name}} 的评论:{{excerpt}}',
            'link' => '/posts/{{post_slug}}',
        ],
        'comment.pending' => [
            'title' => '评论待审核',
            'body' => '文章《{{post_title}}》收到新评论,等待审核(来自 {{author_name}})',
            'link' => '/admin/notifications',
        ],
        'post.published' => [
            'title' => '文章已发布',
            'body' => '{{author_name}} 发布了《{{title}}》',
            'link' => '/posts/{{slug}}',
        ],
        'user.level_promoted' => [
            'title' => '等级晋升 · {{new_level}}',
            'body' => '{{username}} 累计经验 {{xp}},等级由 {{old_level}} 晋升为 {{new_level}}',
            'link' => '/admin/dashboard',
        ],
        'system.test' => [
            'title' => '测试通知',
            'body' => '这是一条来自 PenBlog 的测试通知,用于验证通知管线。',
            'link' => '/admin/notifications',
        ],
    ];

    /** @param array<string, mixed> $payload @return array{title: string, body: string, link: string}|null */
    public function render(string $templateKey, string $channel, array $payload): ?array
    {
        $tpl = self::TEMPLATES[$templateKey] ?? null;
        if ($tpl === null) {
            return null;
        }
        $fill = static fn (string $s): string => (string) preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static fn (array $m): string => (string) ($payload[$m[1]] ?? ''),
            $s,
        );
        $body = $fill($tpl['body']);
        if ($channel === 'in_app') {
            $body = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }
        return ['title' => $fill($tpl['title']), 'body' => $body, 'link' => $fill($tpl['link'])];
    }

    public static function knows(string $templateKey): bool
    {
        return isset(self::TEMPLATES[$templateKey]);
    }
}
