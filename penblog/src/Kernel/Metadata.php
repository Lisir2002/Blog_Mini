<?php

declare(strict_types=1);

namespace PenBlog\Kernel;

/**
 * Extension 三段模型之 metadata(见 02 §4)。
 */
final class Metadata
{
    public function __construct(
        public readonly string $name,
        public readonly string $uid,
        public readonly string $kind,
        public readonly string $apiVersion = 'penblog.dev/v1',
        public readonly ?string $creationTimestamp = null,
        public readonly ?string $updateTimestamp = null,
        public readonly int $resourceVersion = 1,
    ) {
    }

    public static function generate(string $name, string $kind): self
    {
        return new self(
            name: $name,
            uid: bin2hex(random_bytes(16)),
            kind: $kind,
            creationTimestamp: gmdate('Y-m-d\TH:i:s\Z'),
            updateTimestamp: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'uid' => $this->uid,
            'kind' => $this->kind,
            'apiVersion' => $this->apiVersion,
            'creationTimestamp' => $this->creationTimestamp,
            'updateTimestamp' => $this->updateTimestamp,
            'resourceVersion' => $this->resourceVersion,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            uid: (string) $data['uid'],
            kind: (string) $data['kind'],
            apiVersion: (string) ($data['apiVersion'] ?? 'penblog.dev/v1'),
            creationTimestamp: isset($data['creationTimestamp']) ? (string) $data['creationTimestamp'] : null,
            updateTimestamp: isset($data['updateTimestamp']) ? (string) $data['updateTimestamp'] : null,
            resourceVersion: (int) ($data['resourceVersion'] ?? 1),
        );
    }
}
