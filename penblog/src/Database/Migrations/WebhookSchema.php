<?php

declare(strict_types=1);

namespace PenBlog\Database\Migrations;

use PenBlog\Repository\Migration;
use PenBlog\Repository\Schema;

/**
 * 0005-webhook(06 §8):事件订阅表 + 投递日志(退避/死信,与通知管线同语义)。
 */
final class WebhookSchema implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('webhooks', function ($t): void {
            $t->id();
            $t->string('url', 500);
            $t->string('secret', 64);
            $t->text('events_json');
            $t->boolean('active', ['default' => true]);
            $t->bigInteger('created_by', ['nullable' => true, 'references' => ['users', 'id'], 'onDelete' => 'cascade']);
            $t->string('created_at', 32);
            $t->string('updated_at', 32);
            $t->index(['active']);
        });

        $schema->create('webhook_deliveries', function ($t): void {
            $t->id();
            $t->bigInteger('webhook_id', ['references' => ['webhooks', 'id'], 'onDelete' => 'cascade']);
            $t->string('event', 64);
            $t->string('event_id', 64);
            $t->text('payload');
            $t->string('status', 24, ['default' => 'pending']);
            $t->check("status IN ('pending','delivered','failed','dead_lettered')");
            $t->integer('attempts', ['default' => 0]);
            $t->integer('response_code', ['nullable' => true]);
            $t->string('error', 255, ['nullable' => true]);
            $t->string('next_retry_at', 32, ['nullable' => true]);
            $t->string('created_at', 32);
            $t->string('updated_at', 32);
            $t->index(['status', 'next_retry_at']);
            $t->index(['webhook_id', 'id']);
        });
    }
}
