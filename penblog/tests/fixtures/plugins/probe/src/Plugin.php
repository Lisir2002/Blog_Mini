<?php

declare(strict_types=1);

namespace Probe;

use Nyholm\Psr7\Response;
use PenBlog\Plugin\PermissionDenied;
use PenBlog\Plugin\PluginContext;
use PenBlog\Plugin\PluginInterface;
use Psr\Http\Message\ResponseInterface;

final class Plugin implements PluginInterface
{
    public function onActivate(PluginContext $ctx): void
    {
        $ctx->registerRoute('GET', '/probe', static fn (): ResponseInterface => new Response(200, ['Content-Type' => 'text/plain'], 'probe'));
        $ctx->events()->listen('probe.hook', static function () use ($ctx): void {
            $ctx->options()->set('fired', (int) $ctx->options()->get('fired', 0) + 1);
        });
        $ctx->options()->set('marker', 'written-by-probe');
        $ctx->secrets()->put('token', 's3cr3t-value');
        try {
            $ctx->secrets()->get('evil');
            $ctx->options()->set('secret_denied', false);
        } catch (PermissionDenied) {
            $ctx->options()->set('secret_denied', true);
        }
    }

    public function onDeactivate(): void
    {
    }

    public function onUninstall(): void
    {
    }
}
