<?php

declare(strict_types=1);

namespace PenBlog\Database\Migrations;

use PenBlog\Repository\Migration;
use PenBlog\Repository\Schema;

/**
 * 0003-content(07 §1/§2):内容投影表 posts/categories/tags/关联表/comments。
 * Post 文档本体存 extensions(双写,事务内同步投影)。
 */
final class ContentSchema implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('posts', function ($t): void {
            $t->id();
            $t->string('uid', 64, ['unique' => true]);
            $t->bigInteger('user_id', ['references' => ['users', 'id']]);
            $t->string('title', 255);
            $t->string('slug', 255, ['unique' => true]);
            $t->text('body_md');
            $t->text('body_html', ['nullable' => true]);
            $t->string('excerpt', 500, ['nullable' => true]);
            $t->string('status', 16, ['default' => 'draft']);
            $t->check("status IN ('draft','published','archived')");
            $t->string('published_at', 32, ['nullable' => true]);
            $t->string('created_at', 32);
            $t->string('updated_at', 32);
            $t->string('deleted_at', 32, ['nullable' => true]);
            $t->index(['status', 'published_at']);
            $t->index(['user_id', 'status']);
        });

        $schema->create('categories', function ($t): void {
            $t->id();
            $t->string('name', 64, ['unique' => true]);
            $t->string('slug', 64, ['unique' => true]);
            $t->text('description', ['nullable' => true]);
            $t->string('created_at', 32);
        });

        $schema->create('tags', function ($t): void {
            $t->id();
            $t->string('name', 64, ['unique' => true]);
            $t->string('slug', 64, ['unique' => true]);
            $t->string('created_at', 32);
        });

        $schema->create('post_categories', function ($t): void {
            $t->id();
            $t->bigInteger('post_id', ['references' => ['posts', 'id'], 'onDelete' => 'cascade']);
            $t->bigInteger('category_id', ['references' => ['categories', 'id'], 'onDelete' => 'cascade']);
            $t->unique(['post_id', 'category_id']);
        });

        $schema->create('post_tags', function ($t): void {
            $t->id();
            $t->bigInteger('post_id', ['references' => ['posts', 'id'], 'onDelete' => 'cascade']);
            $t->bigInteger('tag_id', ['references' => ['tags', 'id'], 'onDelete' => 'cascade']);
            $t->unique(['post_id', 'tag_id']);
        });

        $schema->create('comments', function ($t): void {
            $t->id();
            $t->bigInteger('post_id', ['references' => ['posts', 'id'], 'onDelete' => 'cascade']);
            $t->bigInteger('user_id', ['references' => ['users', 'id'], 'nullable' => true]);
            $t->string('author_name', 64, ['nullable' => true]);
            $t->string('author_email', 255, ['nullable' => true]);
            $t->bigInteger('parent_id', ['references' => ['comments', 'id'], 'nullable' => true]);
            $t->text('body');
            $t->string('status', 16, ['default' => 'pending']);
            $t->check("status IN ('pending','approved','spam','trash')");
            $t->string('created_at', 32);
            $t->index(['post_id', 'status', 'created_at']);
        });
    }
}
