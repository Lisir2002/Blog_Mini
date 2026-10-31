<?php

declare(strict_types=1);

namespace PenBlog\Tests\Integration;

/**
 * 06 §7 OpenAPI 运行时导出:公开可取、结构完整、权限标注、无未登记路由。
 */
final class OpenApiTest extends KernelTestCase
{
    /** @return array<string, mixed> */
    private function spec(): array
    {
        $res = $this->kernel->handle($this->request('GET', '/api/v1/openapi.json'));
        self::assertSame(200, $res->getStatusCode(), '文档端点应匿名可取');
        $spec = json_decode((string) $res->getBody(), true);
        self::assertIsArray($spec);
        return $spec;
    }

    public function testDocumentShape(): void
    {
        $spec = $this->spec();
        self::assertSame('3.0.3', $spec['openapi']);
        self::assertSame('PenBlog API', $spec['info']['title']);
        self::assertArrayHasKey('paths', $spec);
        self::assertArrayHasKey('sessionCookie', $spec['components']['securitySchemes']);
        self::assertArrayHasKey('patBearer', $spec['components']['securitySchemes']);
    }

    public function testKeyPathsRegistered(): void
    {
        $paths = $this->spec()['paths'];
        foreach (['/api/v1/posts', '/api/v1/webhooks', '/api/v1/health', '/api/v1/openapi.json'] as $p) {
            self::assertArrayHasKey($p, $paths, "缺少路径 {$p}");
        }
        self::assertArrayHasKey('post', $paths['/api/v1/posts']);
        self::assertArrayHasKey('delete', $paths['/api/v1/webhooks/{id}']);
    }

    public function testPublicVersusSecuredOperations(): void
    {
        $paths = $this->spec()['paths'];
        self::assertSame([], $paths['/api/v1/posts']['get']['security'], '公开列表 security 为空');
        self::assertNotEmpty($paths['/api/v1/posts']['post']['security']);
        self::assertSame('system:manage', $paths['/api/v1/webhooks']['get']['x-permission']);
        self::assertSame('post:write', $paths['/api/v1/posts']['post']['x-permission']);
    }

    public function testCollectionGetCarriesListParameters(): void
    {
        $params = $this->spec()['paths']['/api/v1/posts']['get']['parameters'];
        $refs = array_column($params, '$ref');
        foreach (['#/components/parameters/Page', '#/components/parameters/Size', '#/components/parameters/Cursor', '#/components/parameters/Sort'] as $ref) {
            self::assertContains($ref, $refs);
        }
    }

    public function testPathParametersExtracted(): void
    {
        $params = $this->spec()['paths']['/api/v1/posts/{slug}']['get']['parameters'];
        self::assertSame('slug', $params[0]['name']);
        self::assertSame('path', $params[0]['in']);
        self::assertTrue($params[0]['required']);
    }

    public function testNoUndocumentedRoutes(): void
    {
        $undocumented = [];
        foreach ($this->spec()['paths'] as $pattern => $ops) {
            foreach ($ops as $method => $op) {
                if ($op['x-undocumented'] ?? false) {
                    $undocumented[] = strtoupper($method) . ' ' . $pattern;
                }
            }
        }
        self::assertSame([], $undocumented, '所有 /api/v1 路由都应登记 META 元数据');
    }
}
