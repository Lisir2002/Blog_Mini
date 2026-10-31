<?php

declare(strict_types=1);

namespace PenBlog\Notification;

use PenBlog\Auth\UserRepository;
use PenBlog\Kernel\Kernel;
use PenBlog\Notification\Events\CommentCreated;
use PenBlog\Notification\Events\LevelPromoted;
use PenBlog\Notification\Events\PostPublished;
use Psr\Log\LoggerInterface;

/**
 * 05 §3 事件接线:领域事件 → NotifyRequest → 管线。
 * 红线:通知失败绝不阻断业务(吞异常记日志)。
 */
final class NotificationService
{
    public static function register(Kernel $kernel): void
    {
        $c = $kernel->container;
        $pipeline = static fn (): NotificationPipeline => $c->get(NotificationPipeline::class);
        $users = static fn (): UserRepository => $c->get(UserRepository::class);
        $logger = static fn (): LoggerInterface => $c->get(LoggerInterface::class);

        $safe = static function (callable $fn) use ($logger): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $logger()->warning('notification failed: ' . $e->getMessage(), ['exception' => get_class($e)]);
            }
        };

        $kernel->events->listen(PostPublished::class, static function (PostPublished $e) use ($pipeline, $safe): void {
            $safe(static fn () => $pipeline()->process(new NotifyRequest(
                eventId: NotifyRequest::uuid(),
                category: 'content',
                recipient: 'role:manager',
                templateKey: 'post.published',
                payload: ['slug' => $e->slug, 'title' => $e->title, 'author_name' => $e->authorUsername],
                dedupKey: 'post.published:' . $e->slug,
                link: '/posts/' . rawurlencode($e->slug),
            )));
        });

        $kernel->events->listen(CommentCreated::class, static function (CommentCreated $e) use ($pipeline, $users, $safe, $c): void {
            $payload = [
                'post_title' => $e->postTitle,
                'post_slug' => $e->postSlug,
                'author_name' => $e->authorName,
                'excerpt' => $e->excerpt,
            ];
            if ($e->status === 'pending') {
                // 待审 → 管理员(05 §1:comment 默认 in_app+email)
                $safe(static fn () => $pipeline()->process(new NotifyRequest(
                    eventId: NotifyRequest::uuid(),
                    category: 'comment',
                    recipient: 'role:admin',
                    templateKey: 'comment.pending',
                    payload: $payload,
                    dedupKey: 'comment.pending:' . $e->commentId,
                    link: '/admin/notifications',
                )));
                return;
            }
            // 已过审 → 文章作者(排除本人)
            $author = $users()->findByUsername($e->postAuthorUsername);
            if ($author !== null && $author->id() !== $e->userId) {
                $safe(static fn () => $pipeline()->process(new NotifyRequest(
                    eventId: NotifyRequest::uuid(),
                    category: 'comment',
                    recipient: $author->id(),
                    templateKey: 'comment.new',
                    payload: $payload,
                    dedupKey: 'comment.new:' . $e->commentId,
                    link: '/posts/' . rawurlencode($e->postSlug),
                )));
            }
            // 回复 → 父评论作者(排除本人)
            if ($e->parentId !== null) {
                $stmt = $c->get(\PenBlog\Repository\SqlDriver::class)->pdo()
                    ->prepare('SELECT user_id FROM comments WHERE id = :p');
                $stmt->execute(['p' => $e->parentId]);
                $row = $stmt->fetch();
                $parentUserId = $row !== false && $row['user_id'] !== null ? (int) $row['user_id'] : null;
                if ($parentUserId !== null && $parentUserId !== $e->userId && $parentUserId !== ($author?->id() ?? -1)) {
                    $safe(static fn () => $pipeline()->process(new NotifyRequest(
                        eventId: NotifyRequest::uuid(),
                        category: 'comment',
                        recipient: $parentUserId,
                        templateKey: 'comment.new',
                        payload: $payload,
                        dedupKey: 'comment.reply:' . $e->commentId,
                        link: '/posts/' . rawurlencode($e->postSlug),
                    )));
                }
            }
        });

        // 经验晋升(09 §2)→ 本人 system 类通知
        $kernel->events->listen(LevelPromoted::class, static function (LevelPromoted $e) use ($pipeline, $safe): void {
            $safe(static fn () => $pipeline()->process(new NotifyRequest(
                eventId: NotifyRequest::uuid(),
                category: 'system',
                recipient: $e->userId,
                templateKey: 'user.level_promoted',
                payload: [
                    'username' => $e->username,
                    'old_level' => 'L' . $e->oldLevel,
                    'new_level' => 'L' . $e->newLevel,
                    'xp' => $e->xp,
                ],
                dedupKey: 'level.promoted:' . $e->userId . ':' . $e->newLevel,
                link: '/admin/dashboard',
            )));
        });
    }
}
