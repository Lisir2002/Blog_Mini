<?php

declare(strict_types=1);

namespace PenBlog\Plugin;

/**
 * SettingSection 扩展点(04 §4):声明式配置区块,后台插件页自动渲染表单,
 * 值存于 plugin.{name}.settings JSON(命名空间隔离,04 §3)。
 */
final class SettingSection
{
    public const FIELD_TYPES = ['text', 'textarea', 'checkbox', 'number'];

    /** @param list<array{name:string, label:string, type:string, default?:mixed}> $fields */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $fields,
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $id)) {
            throw new \InvalidArgumentException('SettingSection id must be kebab-case');
        }
        if ($title === '' || mb_strlen($title) > 96) {
            throw new \InvalidArgumentException('SettingSection title must be 1-96 chars');
        }
        if ($fields === [] || count($fields) > 20) {
            throw new \InvalidArgumentException('SettingSection needs 1-20 fields');
        }
        foreach ($fields as $field) {
            if (!preg_match('/^[a-z0-9_]{1,64}$/', (string) ($field['name'] ?? ''))) {
                throw new \InvalidArgumentException('SettingSection field name invalid: ' . json_encode($field));
            }
            if (!in_array((string) ($field['type'] ?? ''), self::FIELD_TYPES, true)) {
                throw new \InvalidArgumentException('SettingSection field type invalid: ' . (string) ($field['type'] ?? ''));
            }
        }
    }
}
