---
name: "php-testing"
description: "PHPUnit unit/integration/functional testing for PHP blog apps: phpunit.xml setup, AAA pattern, data providers, mocks, in-memory SQLite. Invoke when writing, running, or reviewing tests."
---

# PHP Testing (PHPUnit)

Guidance for testing a PHP + MySQL/SQLite blog application.

## Setup

```bash
composer require --dev phpunit/phpunit
./vendor/bin/phpunit
```

`phpunit.xml` — separate suites so slow integration tests don't run on every save:

```xml
<phpunit bootstrap="vendor/autoload.xml" colors="true">
  <testsuites>
    <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    <testsuite name="Integration"><directory>tests/Integration</directory></testsuite>
  </testsuites>
  <php>
    <env name="DB_DSN" value="sqlite::memory:"/>
  </php>
</phpunit>
```

## Writing good tests

- **Arrange–Act–Assert**: clearly separate setup, the action under test, and verification.
- **Test names describe behavior**, not method names: `rejectsPostWithEmptyTitle()` beats `testProcess()`.
- One behavior per test; keep tests independent (no reliance on execution order or another test's result).
- Prefer `assertSame` (strict) over `assertEquals`.
- Cover edge cases: invalid inputs, boundary values, empty values, expected exceptions.
- Use **data providers** instead of duplicating a test method for multiple inputs:

```php
public static function slugs(): array {
    return [['Hello World!', 'hello-world'], ['PHP 8 & PDO', 'php-8-pdo']];
}
#[DataProvider('slugs')]
public function testSlugGeneration(string $title, string $expected): void {
    $this->assertSame($expected, Slugger::fromTitle($title));
}
```

- Use realistic test data; keep it minimal and relevant.

## Unit vs integration

- Unit tests: pure logic (slug generation, Markdown rendering, validation) — no DB, no I/O.
- Integration tests: repositories against **in-memory SQLite** (`sqlite::memory:`), create schema in `setUp()`, so tests are fast and isolated.

```php
protected function setUp(): void {
    $this->pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $this->pdo->exec(file_get_contents(__DIR__ . '/../schema.sqlite.sql'));
}
```

- Functional tests: boot the front controller/router and assert on HTTP status + rendered output for key routes (home, post page, login, publish).
- Mock collaborators with `createMock(PostRepository::class)` when testing controllers/services in isolation; set expectations with `expects()->method()->willReturn()`.

## Security-sensitive tests (must-have for a blog)

- Auth: login success/failure, password hashing verified, session fixation.
- CSRF: state-changing request without token is rejected.
- SQL injection: input like `"'; DROP TABLE posts; --"` returns data, table intact.
- XSS: stored Markdown/comment with `<script>` renders escaped.

## Running

- `./vendor/bin/phpunit --testdox` for readable output; `--filter` to run one test; fail the build on any error, never skip tests to get green.
