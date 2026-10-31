<?php

declare(strict_types=1);

namespace PenBlog\Database\Migrations;

use PenBlog\Repository\Migration;
use PenBlog\Repository\Schema;

/**
 * 0004-notification(05 §8/§4):站内信收件表 + 轨迹追加日志 + 偏好一等实体。
 */
final class NotificationSchema implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('notifications', function ($t): void {
            $t->id();
            $t->string('event_id', 64);
            $t->bigInteger('recipient_id', ['references' => ['users', 'id'], 'onDelete' => 'cascade']);
            $t->string('category', 16);
            $t->string('template_key', 64);
            $t->string('title', 255);
            $t->text('body_html', ['nullable' => true]);
            $t->string('link', 255, ['nullable' => true]);
            $t->string('importance', 16, ['default' => 'normal']);
            $t->check("importance IN ('normal','critical')");
            $t->string('status', 24, ['default' => 'ingested']);
            $t->check("status IN ('ingested','routed','rendered','render_failed','delivered','partial','failed','suppressed','dead_lettered')");
            $t->text('channels_json', ['nullable' => true]);
            $t->text('results_json', ['nullable' => true]);
            $t->string('dedup_key', 128, ['nullable' => true]);
            $t->integer('attempts', ['default' => 0]);
            $t->string('next_retry_at', 32, ['nullable' => true]);
            $t->string('read_at', 32, ['nullable' => true]);
            $t->string('archived_at', 32, ['nullable' => true]);
            $t->string('created_at', 32);
            $t->string('updated_at', 32);
            // 05 §8:未读计数/列表走 (recipient_id, created_at) 覆盖索引
            $t->index(['recipient_id', 'created_at']);
            $t->index(['recipient_id', 'read_at']);
            $t->index(['status', 'next_retry_at']);
            $t->unique(['event_id', 'recipient_id']);
        });

        $schema->create('notification_events', function ($t): void {
            $t->id();
            $t->string('event_id', 64);
            $t->bigInteger('notification_id', ['nullable' => true]);
            $t->string('stage', 32);
            $t->string('channel', 16, ['nullable' => true]);
            $t->text('detail', ['nullable' => true]);
            $t->string('created_at', 32);
            $t->index(['event_id', 'created_at']);
        });

        $schema->create('notification_preferences', function ($t): void {
            $t->id();
            $t->bigInteger('user_id', ['references' => ['users', 'id'], 'onDelete' => 'cascade']);
            $t->string('category', 16);
            $t->boolean('use_default', ['default' => true]);
            $t->text('channels_json', ['nullable' => true]);
            $t->string('quiet_start', 8, ['nullable' => true]);
            $t->string('quiet_end', 8, ['nullable' => true]);
            $t->string('quiet_tz', 64, ['nullable' => true]);
            $t->string('created_at', 32);
            $t->string('updated_at', 32);
            $t->unique(['user_id', 'category']);
        });
    }
}
