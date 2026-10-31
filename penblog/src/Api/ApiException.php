<?php

declare(strict_types=1);

namespace PenBlog\Api;

/**
 * 统一错误(06 §5):status + code + details + 可选响应头。
 */
class ApiException extends \RuntimeException
{
    /**
     * @param list<array{field?:string, reason?:string}> $details
     * @param array<string, string>                      $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, 'not_found', "{$what} not found");
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self(401, 'unauthenticated', $message);
    }

    public static function forbidden(string $message = 'Permission denied'): self
    {
        return new self(403, 'permission_denied', $message);
    }

    public static function conflict(string $message, string $code = 'conflict'): self
    {
        return new self(409, $code, $message);
    }
}
