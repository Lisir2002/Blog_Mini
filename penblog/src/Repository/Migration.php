<?php

declare(strict_types=1);

namespace PenBlog\Repository;

interface Migration
{
    public function up(Schema $schema): void;
}
