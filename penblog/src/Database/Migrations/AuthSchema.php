<?php

declare(strict_types=1);

namespace PenBlog\Database\Migrations;

use PenBlog\Repository\Migration;
use PenBlog\Repository\Schema;

/**
 * 0002-auth(09 §5):users 扩展列 + sessions + personal_access_tokens + user_xp_events。
 * ALTER ADD COLUMN 为 MySQL/SQLite 通用语法;status 取值校验在应用层(UserRepository)。
 */
final class AuthSchema implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->raw('ALTER TABLE users ADD COLUMN level INTEGER NOT NULL DEFAULT 3');
        $schema->raw('ALTER TABLE users ADD COLUMN xp INTEGER NOT NULL DEFAULT 0');
        $schema->raw("ALTER TABLE users ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active'");
        $schema->raw('ALTER TABLE users ADD COLUMN avatar VARCHAR(255)');
        $schema->raw('ALTER TABLE users ADD COLUMN bio VARCHAR(500)');
        $schema->raw('ALTER TABLE users ADD COLUMN homepage VARCHAR(255)');
        $schema->raw('ALTER TABLE users ADD COLUMN last_active_at VARCHAR(32)');
        $schema->raw('ALTER TABLE users ADD COLUMN deleted_at VARCHAR(32)');
        $schema->raw('CREATE INDEX users_level_idx ON users (level)');

        $schema->create('sessions', function ($t): void {
            $t->id();
            $t->string('token_hash', 64, ['unique' => true]);
            $t->bigInteger('user_id', ['references' => ['users', 'id'], 'onDelete' => 'cascade']);
            $t->string('ip', 45, ['nullable' => true]);
            $t->string('user_agent', 255, ['nullable' => true]);
            $t->string('expires_at', 32);
            $t->string('created_at', 32);
            $t->index(['user_id', 'expires_at']);
        });

        $schema->create('personal_access_tokens', function ($t): void {
            $t->id();
            $t->string('name', 64);
            $t->string('token_prefix', 16, ['unique' => true]);
            $t->string('token_hash', 255);
            $t->bigInteger('user_id', ['references' => ['users', 'id'], 'onDelete' => 'cascade']);
            $t->text('scopes');
            $t->string('expires_at', 32, ['nullable' => true]);
            $t->string('created_at', 32);
        });

        $schema->create('user_xp_events', function ($t): void {
            $t->id();
            $t->bigInteger('user_id', ['references' => ['users', 'id'], 'onDelete' => 'cascade']);
            $t->string('metric', 32);
            $t->integer('amount');
            $t->string('event_key', 64, ['unique' => true]);
            $t->string('created_at', 32);
        });
    }
}
