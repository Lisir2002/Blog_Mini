<?php

declare(strict_types=1);

namespace PenBlog\Theme;

/**
 * theme.json 清单(03 §3/§5):元数据 + 设计令牌 + darkMode + Setting 声明。
 * 校验失败即 ThemeManifestException,错误项全量返回。
 */
final class ThemeManifest
{
    public const DARK_STRATEGIES = ['media', 'class'];
    public const SETTING_TYPES = ['boolean', 'number', 'string', 'textarea', 'radio'];

    /**
     * @param list<string> $tags
     * @param array<string, string> $tokens dot-key => 值
     * @param array{strategy:string, tokens:array<string,string>} $darkMode
     * @param list<array{name:string, type:string, label:string, default?:mixed, min?:float, max?:float, options?:list<string>}> $settings
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $displayName,
        public readonly string $author,
        public readonly string $description,
        public readonly array $tags,
        public readonly ?string $extends,
        public readonly array $tokens,
        public readonly array $darkMode,
        public readonly array $settings,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ThemeManifestException(['cannot read file'], $path);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new ThemeManifestException(['invalid JSON: ' . json_last_error_msg()], $path);
        }
        return self::fromArray($json, basename(dirname($path)) . '/theme.json');
    }

    /** @param array<string, mixed> $json */
    public static function fromArray(array $json, string $source = 'theme.json'): self
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

        $extends = isset($json['extends']) && $json['extends'] !== null && $json['extends'] !== ''
            ? (string) $json['extends'] : null;
        if ($extends !== null) {
            if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $extends)) {
                $errors[] = "extends must be kebab-case: {$extends}";
            } elseif ($extends === $name) {
                $errors[] = 'extends must not reference itself';
            }
        }

        $tokens = self::validateTokens($json['tokens'] ?? [], 'tokens', $errors);

        $darkMode = ['strategy' => 'class', 'tokens' => []];
        $rawDark = $json['darkMode'] ?? null;
        if (is_array($rawDark)) {
            $strategy = (string) ($rawDark['strategy'] ?? 'class');
            if (!in_array($strategy, self::DARK_STRATEGIES, true)) {
                $errors[] = "darkMode.strategy must be one of: " . implode('|', self::DARK_STRATEGIES);
            } else {
                $darkMode['strategy'] = $strategy;
            }
            $darkMode['tokens'] = self::validateTokens($rawDark['tokens'] ?? [], 'darkMode.tokens', $errors);
        }

        $settings = self::validateSettings($json['settings'] ?? [], $errors);

        if ($errors !== []) {
            throw new ThemeManifestException($errors, $source);
        }

        return new self(
            name: $name,
            version: $version,
            displayName: (string) ($json['displayName'] ?? $name),
            author: (string) ($json['author'] ?? ''),
            description: (string) ($json['description'] ?? ''),
            tags: array_values(array_map('strval', (array) ($json['tags'] ?? []))),
            extends: $extends,
            tokens: $tokens,
            darkMode: $darkMode,
            settings: $settings,
        );
    }

    /**
     * @param mixed $raw
     * @param list<string> $errors
     * @return array<string, string>
     */
    private static function validateTokens(mixed $raw, string $label, array &$errors): array
    {
        if (!is_array($raw)) {
            $errors[] = "{$label} must be an object";
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (!preg_match('/^[a-z][a-z0-9-]*(\.[a-zA-Z][a-zA-Z0-9-]*)*$/', $key)) {
                $errors[] = "{$label} key invalid: {$key}";
                continue;
            }
            if (!is_string($value) || !preg_match('/^[\s#0-9a-zA-Z(),.%:\/+-]+$/', $value) || mb_strlen($value) > 200) {
                $errors[] = "{$label}.{$key} value unsafe: " . (is_string($value) ? mb_substr($value, 0, 40) : gettype($value));
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @param list<string> $errors
     * @return list<array<string, mixed>>
     */
    private static function validateSettings(mixed $raw, array &$errors): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            $errors[] = 'settings must be an array';
            return [];
        }
        $out = [];
        foreach ($raw as $i => $s) {
            if (!is_array($s)) {
                $errors[] = "settings[{$i}] must be an object";
                continue;
            }
            $decl = [];
            $key = (string) ($s['name'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                $errors[] = "settings[{$i}].name invalid: {$key}";
                continue;
            }
            $decl['name'] = $key;
            $type = (string) ($s['type'] ?? '');
            if (!in_array($type, self::SETTING_TYPES, true)) {
                $errors[] = "settings[{$key}].type must be one of: " . implode('|', self::SETTING_TYPES);
                continue;
            }
            $decl['type'] = $type;
            $label = trim((string) ($s['label'] ?? ''));
            if ($label === '') {
                $errors[] = "settings[{$key}].label must not be empty";
                continue;
            }
            $decl['label'] = $label;
            if (array_key_exists('default', $s)) {
                $decl['default'] = $s['default'];
            }
            if ($type === 'number') {
                foreach (['min', 'max'] as $bound) {
                    if (isset($s[$bound]) && is_numeric($s[$bound])) {
                        $decl[$bound] = 0 + $s[$bound];
                    }
                }
                if (isset($decl['default']) && !is_numeric($decl['default'])) {
                    $errors[] = "settings[{$key}].default must be numeric";
                    continue;
                }
            }
            if ($type === 'radio') {
                $options = $s['options'] ?? null;
                if (!is_array($options) || $options === []) {
                    $errors[] = "settings[{$key}].options required for radio";
                    continue;
                }
                $decl['options'] = array_values(array_map('strval', $options));
                if (isset($decl['default']) && !in_array((string) $decl['default'], $decl['options'], true)) {
                    $errors[] = "settings[{$key}].default must be one of options";
                    continue;
                }
            }
            if ($type === 'boolean' && isset($decl['default']) && !is_bool($decl['default'])) {
                $decl['default'] = (bool) $decl['default'];
            }
            $out[] = $decl;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'displayName' => $this->displayName,
            'author' => $this->author,
            'description' => $this->description,
            'tags' => $this->tags,
            'extends' => $this->extends,
        ];
    }
}
