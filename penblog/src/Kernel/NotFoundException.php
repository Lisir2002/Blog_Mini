<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
}
