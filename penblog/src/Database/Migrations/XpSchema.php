<?php

declare(strict_types=1);

namespace PenBlog\Database\Migrations;

use PenBlog\Repository\Migration;
use PenBlog\Repository\Schema;

/**
 * 0006-xp(09 §2/§5):user_xp_events 表已在 0002 建出(event_key 唯一约束即幂等键),
 * 本迁移补记 xp 列与"当日累计量"查询索引(每日上限防刷用)。
 */
final class XpSchema implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->raw('ALTER TABLE user_xp_events ADD COLUMN xp INTEGER NOT NULL DEFAULT 0');
        $schema->raw('CREATE INDEX user_xp_events_user_metric_idx ON user_xp_events (user_id, metric, created_at)');
    }
}
