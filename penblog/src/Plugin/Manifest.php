<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

/**
 * plugin.json 清单(04 §1):校验失败即 ManifestException,错误项全量返回。
 */
final class Manifest
{
    /** permissions 白名单(04 §3/§4):Context 能力与权限串一一对应。 */
    public const PERMISSIONS = [
        'event.listen',
        'router.register',
        'menu.register',
        'settings.register',
        'extension.register',
        'option.read',
        'option.write',
        'secret.read',
        'secret.write',
    ];

    /**
     * @param array{penblog?:string, php?:string, plugins?:list<string>} $requires
     * @param list<string> $permissions
     * @param list<string> $secretRefs
     * @param list<string> $providesHooks
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $entry,
        public readonly array $requires,
        public readonly array $permissions,
        public readonly array $secretRefs,
        public readonly string $description,
        public readonly array $providesHooks,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ManifestException(['cannot read file'], $path);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new ManifestException(['invalid JSON: ' . json_last_error_msg()], $path);
        }
        return self::fromArray($json, basename(dirname($path)) . '/plugin.json');
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json, string $source = 'plugin.json'): self
    {
        $errors = [];

        $name = (string) ($json['name'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $name)) {
            $errors[] = 'name must be kebab-case (^[a-z][a-z0-9-]{1,63}$)';
        }

        $version = (string) ($json['version'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
            $errors[] = 'version must be semver (x.y.z)';
        }

        $entry = (string) ($json['entry'] ?? '');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $entry)) {
            $errors[] = 'entry must be a namespaced FQCN';
        }

        $requires = [];
        $rawReq = $json['requires'] ?? [];
        if (is_array($rawReq)) {
            foreach (['penblog', 'php'] as $key) {
                if (isset($rawReq[$key])) {
                    $constraint = trim((string) $rawReq[$key]);
                    if (!preg_match('/^(>=|<=|>|<|==|!=)?\s*\d+(\.\d+){0,3}$/', $constraint)) {
                        $errors[] = "requires.{$key} constraint invalid: {$constraint}";
                    } else {
                        $requires[$key] = $constraint;
                    }
                }
            }
            $deps = $rawReq['plugins'] ?? [];
            if (is_array($deps)) {
                foreach ($deps as $dep) {
                    if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', (string) $dep)) {
                        $errors[] = 'requires.plugins contains invalid name: ' . (string) $dep;
                    }
                }
                $requires['plugins'] = array_values(array_unique(array_map('strval', $deps)));
            }
        }

        $permissions = [];
        foreach ((array) ($json['permissions'] ?? []) as $perm) {
            if (in_array($perm, self::PERMISSIONS, true)) {
                $permissions[] = (string) $perm;
            } else {
                $errors[] = 'unknown permission: ' . (string) $perm;
            }
        }
        $permissions = array_values(array_unique($permissions));

        $secretRefs = [];
        foreach ((array) ($json['secretRefs'] ?? []) as $ref) {
            if (preg_match('/^[a-z0-9_]{1,64}$/', (string) $ref)) {
                $secretRefs[] = (string) $ref;
            } else {
                $errors[] = 'invalid secretRef: ' . (string) $ref;
            }
        }

        $providesHooks = [];
        foreach ((array) ($json['providesHooks'] ?? []) as $hook) {
            if (preg_match('/^[a-z0-9_.-]{1,64}$/', (string) $hook)) {
                $providesHooks[] = (string) $hook;
            } else {
                $errors[] = 'invalid providesHooks entry: ' . (string) $hook;
            }
        }

        if ($errors !== []) {
            throw new ManifestException($errors, $source);
        }

        return new self(
            name: $name,
            version: $version,
            entry: $entry,
            requires: $requires,
            permissions: $permissions,
            secretRefs: array_values(array_unique($secretRefs)),
            description: (string) ($json['description'] ?? ''),
            providesHooks: array_values(array_unique($providesHooks)),
        );
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'entry' => $this->entry,
            'requires' => $this->requires,
            'permissions' => $this->permissions,
            'secretRefs' => $this->secretRefs,
            'description' => $this->description,
            'providesHooks' => $this->providesHooks,
        ];
    }
}
