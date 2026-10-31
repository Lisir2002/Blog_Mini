<?php

declare(strict_types=1);

namespace PenBlog\Api;

final class ValidationException extends ApiException
{
    /** @param list<array{field:string, reason:string}> $details */
    public function __construct(array $details, string $message = 'Validation failed')
    {
        parent::__construct(400, 'validation_failed', $message, $details);
    }

    public static function required(string $field): self
    {
        return new self([['field' => $field, 'reason' => 'required']], "{$field} 不能为空");
    }
}
