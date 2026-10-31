<?php

declare(strict_types=1);

namespace HelloWorld;

use Nyholm\Psr7\Response;
use PenBlog\Api\Json;
use PenBlog\Plugin\MenuItem;
use PenBlog\Plugin\PluginContext;
use PenBlog\Plugin\PluginInterface;
use PenBlog\Plugin\SettingSection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 04 插件系统示例:RouterPoint + APIEndpoint + MenuItem + SettingSection
 * + 钩子订阅 + 命名空间 Options。禁用后以上注册全部自动撤销。
 */
final class Plugin implements PluginInterface
{
    public function onActivate(PluginContext $ctx): void
    {
        // RouterPoint:前台问候页(公开)。greeting 由后台 SettingSection 表单写入。
        $ctx->registerRoute('GET', '/hello', static function () use ($ctx): ResponseInterface {
            $greeting = (string) $ctx->options()->get('settings.hello-greeting.greeting', 'Hello from PenBlog!');
            return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $greeting . "\n");
        }, summary: 'Hello 问候页');

        // APIEndpoint:插件自定义 JSON 端点(经 OpenAPI 动态登记)
        $ctx->registerRoute('GET', '/api/v1/hello/status', static function () use ($ctx): ResponseInterface {
            return Json::ok(200, [
                'plugin' => 'hello-world',
                'greeting' => (string) $ctx->options()->get('settings.hello-greeting.greeting', 'Hello from PenBlog!'),
                'activatedAt' => $ctx->options()->get('activated_at'),
            ]);
        }, summary: 'Hello 插件状态');

        // MenuItem:后台侧栏追加项
        $ctx->registerMenuItem('admin', new MenuItem('Hello 页', '/hello', 'admin', 60));

        // SettingSection:后台插件页自动渲染配置表单
        $ctx->registerSettingSection(new SettingSection('hello-greeting', 'Hello 设置', [
            ['name' => 'greeting', 'label' => '问候语', 'type' => 'text', 'default' => 'Hello from PenBlog!'],
        ]));

        // 钩子订阅(命名空间约定 plugin.{name}.{event})
        $ctx->events()->listen('plugin.hello-world.greeted', static function (string $who = 'world') use ($ctx): void {
            $ctx->logger()->info("hello-world greeted {$who}");
        });

        if ($ctx->options()->get('activated_at') === null) {
            $ctx->options()->set('activated_at', gmdate('c'));
        }
    }

    public function onDeactivate(): void
    {
        // 注册撤销由 PluginManager 统一回收(04 §2 硬约束),此处仅清理自身资源。
    }

    public function onUninstall(): void
    {
        // plugin.hello-world.* 的 Options/Secret 清理由 PluginManager 负责。
    }
}
