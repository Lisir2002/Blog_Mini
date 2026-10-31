<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PenBlog\Auth\AccessLevel;
use PenBlog\Auth\UserRepository;
use PenBlog\Kernel\Kernel;
use PenBlog\Repository\Migrator;
use Psr\Http\Message\ServerRequestInterface;
use PHPUnit\Framework\TestCase;

/**
 * 集成测试基类:内存 SQLite + 全部迁移(0001-0003)+ 种子(四级 AccessLevel + admin)。
 */
abstract class KernelTestCase extends TestCase
{
    protected Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = Kernel::fromConfig([
            'app' => ['debug' => true, 'url' => 'http://localhost', 'secret_key' => str_repeat('k', 32)],
            'db' => ['driver' => 'sqlite', 'path' => ':memory:'],
            'paths' => ['logs' => sys_get_temp_dir() . '/penblog-test.log'],
        ]);

        $this->kernel->container->get(Migrator::class)->run();
        $this->seed();
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir() . '/penblog-test.log');
    }

    /** 安装种子等价物(09 §1 / 10 §4)。 */
    protected function seed(): void
    {
        foreach (AccessLevel::defaultDefinitions() as $def) {
            $this->kernel->extensions()->register($def);
        }
        $this->kernel->container->get(UserRepository::class)
            ->create('admin', 'admin@example.com', 'password123', 0);
    }

    /** @param array<string, mixed>|null $json @param array<string, string> $headers @param array<string, string> $cookies */
    protected function request(string $method, string $path, ?array $json = null, array $headers = [], array $cookies = []): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, $path);
        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode($json, JSON_UNESCAPED_UNICODE) ?: ''));
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookies !== []) {
            $request = $request->withCookieParams($cookies);
        }
        return $request;
    }

    /** 登录并返回 [会话 Cookie 值, CSRF 值]。 */
    protected function login(string $username, string $password): array
    {
        $response = $this->kernel->handle($this->request('POST', '/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ]));
        self::assertSame(200, $response->getStatusCode(), 'login should succeed: ' . (string) $response->getBody());
        $cookies = self::parseSetCookies($response->getHeader('Set-Cookie'));
        return [
            \PenBlog\Auth\SessionStore::COOKIE => $cookies[\PenBlog\Auth\SessionStore::COOKIE] ?? '',
            \PenBlog\Auth\SessionStore::CSRF_COOKIE => $cookies[\PenBlog\Auth\SessionStore::CSRF_COOKIE] ?? '',
        ];
    }

    /** 带会话 + CSRF 头的安全方法请求助手。 */
    protected function authRequest(string $method, string $path, array $cookies, ?array $json = null): ServerRequestInterface
    {
        return $this->request($method, $path, $json, ['X-CSRF-Token' => $cookies[\PenBlog\Auth\SessionStore::CSRF_COOKIE] ?? ''], $cookies);
    }

    /** @param list<string> $setCookies @return array<string, string> */
    protected static function parseSetCookies(array $setCookies): array
    {
        $out = [];
        foreach ($setCookies as $line) {
            $pair = explode(';', $line, 2)[0];
            $eq = strpos($pair, '=');
            if ($eq !== false) {
                $out[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
            }
        }
        return $out;
    }
}
