<?php

declare(strict_types=1);

namespace PenBlog\Database\Migrations;

use PenBlog\Repository\Migration;
use PenBlog\Repository\Schema;

/**
 * M1 核心表(07 §2):extensions / options / secrets / users。
 */
final class InitialSchema implements Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('extensions', function ($t): void {
            $t->id();
            $t->string('uid', 64, ['unique' => true]);
            $t->string('kind', 64);
            $t->string('name', 191);
            $t->string('api_version', 32, ['default' => 'penblog.dev/v1']);
            $t->text('spec');
            $t->text('status', ['nullable' => true]);
            $t->string('creation_timestamp', 32);
            $t->string('update_timestamp', 32);
            $t->integer('resource_version', ['default' => 1]);
            $t->unique(['kind', 'name']);
            $t->index(['kind', 'creation_timestamp']);
        });

        $schema->create('options', function ($t): void {
            $t->id();
            $t->string('name', 191, ['unique' => true]);
            $t->text('value');
            $t->string('updated_at', 32);
        });

        $schema->create('secrets', function ($t): void {
            $t->id();
            $t->string('name', 191, ['unique' => true]);
            $t->text('value');
            $t->string('updated_at', 32);
        });

        $schema->create('users', function ($t): void {
            $t->id();
            $t->string('username', 64, ['unique' => true]);
            $t->string('email', 255, ['unique' => true]);
            $t->string('password_hash', 255);
            $t->string('display_name', 64);
            $t->string('role', 32, ['default' => 'subscriber']);
            $t->check("role IN ('admin','editor','author','subscriber')");
            $t->string('created_at', 32);
            $t->string('updated_at', 32);
        });
    }
}
