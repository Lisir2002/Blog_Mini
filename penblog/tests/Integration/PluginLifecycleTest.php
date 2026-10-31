<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use PenBlog\Api\ApiException;
use PenBlog\Kernel\Kernel;
use PenBlog\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * 04 §2/§3 生命周期:状态机、可撤销注册、权限红线、失败隔离、依赖拓扑、命名空间隔离。
 */
final class PluginLifecycleTest extends KernelTestCase
{
    private function fixturesDir(): string
    {
        return dirname(__DIR__) . '/fixtures/plugins';
    }

    private function useFixtures(): PluginManager
    {
        $manager = new PluginManager($this->kernel, $this->fixturesDir());
        $this->kernel->container->instance(PluginManager::class, $manager);
        return $manager;
    }

    public function testFullLifecycleWithNamespaceIsolation(): void
    {
        $manager = $this->useFixtures();
        $manager->install('probe');
        self::assertSame(PluginManager::INSTALLED, $manager->state('probe')['state']);

        $manager->activate('probe');
        self::assertSame(PluginManager::ACTIVE, $manager->state('probe')['state']);

        // RouterPoint 生效
        $res = $this->kernel->handle($this->request('GET', '/probe'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('probe', (string) $res->getBody());

        // Options/Secret 命名空间隔离(04 §3)
        self::assertSame('written-by-probe', $this->kernel->options()->get('plugin.probe.marker'));
        self::assertNull($this->kernel->options()->get('probe.marker'), '必须带 plugin.{name}. 前缀');
        self::assertSame('s3cr3t-value', $this->kernel->secrets()->get('plugin.probe.secret.token'));
        self::assertTrue($this->kernel->options()->get('plugin.probe.secret_denied'), '未声明 secretRef 应被拒');

        // 钩子监听生效
        $this->kernel->events->doAction('probe.hook');
        self::assertSame(1, $this->kernel->options()->get('plugin.probe.fired'));

        // 停用:全部注册撤销(硬约束)
        $manager->deactivate('probe');
        self::assertSame(PluginManager::INACTIVE, $manager->state('probe')['state']);
        self::assertSame(404, $this->kernel->handle($this->request('GET', '/probe'))->getStatusCode());
        $this->kernel->events->doAction('probe.hook');
        self::assertSame(1, $this->kernel->options()->get('plugin.probe.fired'), 'deactivate 后监听器不得再触发');

        // 卸载:清理 plugin.{name}.* 数据
        $manager->uninstall('probe');
        self::assertNull($manager->state('probe'));
        self::assertNull($this->kernel->options()->get('plugin.probe.marker'));
        self::assertNull($this->kernel->secrets()->get('plugin.probe.secret.token'));
    }

    public function testPermissionAbuseMarksFailedWithoutBreakingKernel(): void
    {
        $manager = $this->useFixtures();
        $manager->install('perm-abuse');
        try {
            $manager->activate('perm-abuse');
            self::fail('未声明权限的注册应失败');
        } catch (ApiException $e) {
            self::assertSame('plugin_activation_failed', $e->errorCode);
        }
        $rec = $manager->state('perm-abuse');
        self::assertSame(PluginManager::FAILED, $rec['state']);
        self::assertStringContainsString('router.register', (string) $rec['error']);

        // 内核与其他路由不受影响
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/api/v1/health'))->getStatusCode());
        self::assertSame(404, $this->kernel->handle($this->request('GET', '/abuse'))->getStatusCode());
    }

    public function testBrokenPluginIsolatedAndOtherPluginsKeepWorking(): void
    {
        $manager = $this->useFixtures();
        $manager->install('broken');
        $this->expectThrows(fn () => $manager->activate('broken'));
        self::assertSame(PluginManager::FAILED, $manager->state('broken')['state']);

        $manager->install('probe');
        $manager->activate('probe');
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/probe'))->getStatusCode());
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/api/v1/health'))->getStatusCode());
    }

    public function testCircularDependencyRejected(): void
    {
        $manager = $this->useFixtures();
        // 环无法经 install 建立(依赖必须已安装),直接注入状态模拟历史数据
        $this->kernel->options()->set('system.plugins.cycle-a', ['state' => PluginManager::INSTALLED, 'version' => '0.1.0']);
        $this->kernel->options()->set('system.plugins.cycle-b', ['state' => PluginManager::INSTALLED, 'version' => '0.1.0']);
        try {
            $manager->activate('cycle-a');
            self::fail('循环依赖应拒绝激活');
        } catch (ApiException $e) {
            self::assertSame('plugin_dependency_cycle', $e->errorCode);
        }
        self::assertSame(PluginManager::INSTALLED, $manager->state('cycle-a')['state'], '环检测不应标记 failed');
        self::assertSame(PluginManager::INSTALLED, $manager->state('cycle-b')['state']);
    }

    public function testDependencyAutoActivationAndDeactivateGuard(): void
    {
        $manager = $this->useFixtures();
        $manager->install('probe');
        $manager->install('dep-user');
        try {
            $manager->install('dep-user');
            self::fail('重复安装应拒绝');
        } catch (ApiException $e) {
            self::assertSame('plugin_installed', $e->errorCode);
        }

        $manager->activate('dep-user');
        self::assertSame(PluginManager::ACTIVE, $manager->state('probe')['state'], '依赖应自动激活');

        try {
            $manager->deactivate('probe');
            self::fail('被依赖插件不得先停用');
        } catch (ApiException $e) {
            self::assertSame('plugin_dependency_active', $e->errorCode);
        }

        $manager->deactivate('dep-user');
        $manager->deactivate('probe');
        self::assertSame(PluginManager::INACTIVE, $manager->state('probe')['state']);
    }

    public function testBootActivatesPersistedActiveState(): void
    {
        $manager = $this->useFixtures();
        $manager->install('probe');
        // 模拟上一请求激活后的持久状态(新 manager 未持有内存注册)
        $this->kernel->options()->set('system.plugins.probe', [
            'state' => PluginManager::ACTIVE, 'version' => '0.1.0', 'error' => null,
            'installedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'activatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $fresh = new PluginManager($this->kernel, $this->fixturesDir());
        $this->kernel->container->instance(PluginManager::class, $fresh);
        $fresh->boot();
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/probe'))->getStatusCode());
    }

    public function testManifestNameMustMatchDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/pb-mismatch-' . bin2hex(random_bytes(4));
        mkdir($dir . '/wrong-name', 0o777, true);
        file_put_contents($dir . '/wrong-name/plugin.json', json_encode([
            'name' => 'other-name', 'version' => '1.0.0', 'entry' => 'X\\Y', 'permissions' => [],
        ]));
        $manager = new PluginManager($this->kernel, $dir);
        $manager->discover();
        self::assertArrayHasKey('wrong-name', $manager->discoveryErrors());
        self::assertStringContainsString('must match directory', $manager->discoveryErrors()['wrong-name']);
        @unlink($dir . '/wrong-name/plugin.json');
        @rmdir($dir . '/wrong-name');
        @rmdir($dir);
    }

    private function expectThrows(callable $fn): void
    {
        try {
            $fn();
            self::fail('expected ApiException');
        } catch (ApiException) {
            $this->addToAssertionCount(1);
        }
    }
}
