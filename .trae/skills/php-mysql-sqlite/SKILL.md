---
name: "php-mysql-sqlite"
description: "PHP 8 + PDO backend development for blogs with MySQL/SQLite: schema design, prepared statements, transactions, security. Invoke when writing PHP backend code, database layers, APIs, or migrations."
---

# PHP + MySQL/SQLite Blog Backend

Guidance for building blog application backends with modern PHP (8.2+) and PDO over MySQL or SQLite.

## Architecture (framework-free blog)

- One public front controller (`public/index.php`) + tiny router; all routing goes through it.
- Layering: Router → Controller → Repository → PDO. Templates are plain PHP files in `views/`.
- Keep `.php` source outside the web root; only `public/` is served.
- Autoload with Composer PSR-4 (`composer init`, `composer dump-autoload`).

## PDO connection

```php
$pdo = new PDO("sqlite:" . __DIR__ . "/../data/blog.db");
// or MySQL:
$pdo = new PDO("mysql:host=127.0.0.1;dbname=blog;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,          // always throw
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,                   // real prepared statements
]);
```

- Load DB credentials from environment variables (or a `.env` excluded from VCS), never hardcode.
- SQLite: enable foreign keys per connection: `$pdo->exec("PRAGMA foreign_keys = ON");` and use WAL journal mode for concurrency.

## SQL security

- SQL injection is the #1 web vulnerability. **Always use prepared statements**; never interpolate user input into SQL, even with escaping/quoting.

```php
$stmt = $pdo->prepare("SELECT * FROM posts WHERE slug = :slug");
$stmt->execute(['slug' => $_GET['slug']]);
$post = $stmt->fetch();
```

- Whitelist dynamic identifiers (ORDER BY columns, table names) — placeholders cannot be used for identifiers.

## Transactions

- Wrap related writes (e.g., post + tags pivot rows) in `beginTransaction()` / `commit()` / `rollBack()` inside try/catch.
- Batch inserts: <10 rows simple prepare/execute loop; 10–1000 wrap in a transaction; >1000 use multi-row INSERT batches of 500–1000 rows, always with rollback on failure.

## Schema for a blog (works on both engines)

- `users(id, username, password_hash, email, role, created_at)`
- `posts(id, user_id, title, slug UNIQUE, body_md, excerpt, status ENUM/draft|published, published_at, created_at, updated_at)`
- `tags(id, name UNIQUE)`, `post_tags(post_id, tag_id, PRIMARY KEY(post_id, tag_id))`
- `comments(id, post_id, user_id, body, created_at)`
- Index `posts(status, published_at DESC)` and `posts(slug)`; index `post_tags(tag_id)`.
- MySQL: use `utf8mb4`/`utf8mb4_unicode_ci`, `InnoDB`. SQLite: `INTEGER PRIMARY KEY AUTOINCREMENT`, `TEXT` for timestamps or `TEXT` ISO-8601.
- Passwords: `password_hash($pw, PASSWORD_DEFAULT)` / `password_verify()` only.

## Output safety

- Escape all HTML output: `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` in templates.
- Markdown rendering: use a vetted library (e.g., erusev/parsedown with safe mode) or a minimal renderer; strip/sanitize raw HTML to prevent stored XSS.
- CSRF token on every state-changing form; rate-limit login/comment endpoints; log errors server-side, never display them in production.

## MySQL vs SQLite checklist

- SQLite: zero-config, single file, great for dev/small blogs; limit write concurrency.
- MySQL: choose when multi-writer/production scale; keep SQL ANSI-compatible or guard dialect differences (`AUTO_INCREMENT` vs `AUTOINCREMENT`, `NOW()` vs `datetime('now')`) inside the repository layer only.
