<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

/**
 * 一切皆 Extension:metadata / spec / status 三段模型(见 02 §4)。
 * 内置或插件注册的子类可覆写 fromArray 做字段校验/DTO 化。
 */
class Extension
{
    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $status
     */
    public function __construct(
        public Metadata $metadata,
        public array $spec = [],
        public array $status = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'apiVersion' => $this->metadata->apiVersion,
            'kind' => $this->metadata->kind,
            'metadata' => $this->metadata->toArray(),
            'spec' => $this->spec,
            'status' => $this->status,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $status
     */
    public static function fromArray(array $metadata, array $spec = [], array $status = []): static
    {
        return new static(Metadata::fromArray($metadata), $spec, $status);
    }
}
