# 06 API 接口设计

> 原则:**API First**。Extension 注册即自动生成 CRUD API;分页、过滤、鉴权、版本化首版即完备(Google AIP-158 经验)。

## 1. 基本约定

- 前缀 `/api/v1`,JSON(`application/json; charset=utf-8`);时间 RFC 3339 UTC。
- 资源名复数、kebab-case:`/api/v1/posts`、`/api/v1/notification-policies`。
- 自定义方法用冒号:`POST /api/v1/posts/{id}:publish`、`POST /api/v1/notifications/{id}:read`。
- 版本化:破坏性变更升 `/api/v2`,v1 至少并行一年;`Deprecation`/`Sunset` 响应头预告。

## 2. 认证与 RBAC

| 方式 | 场景 | 机制 |
|---|---|---|
| Session Cookie | 管理后台 | 同源 + CSRF Token(双提交) |
| PAT(个人访问令牌) | 外部程序长期接入 | `Authorization: Bearer pb_xxx...`,库中只存 Argon2 哈希;可 scopes 限定 |
| JWT(短期) | 第三方应用 OAuth 流程(M5) | 15 分钟时效 |

RBAC 资源串:`{kind}:{action}`,如 `post:manage`、`comment:moderate`、`notification:read:self`。
角色模板:admin / editor / author / subscriber;插件注册路由时声明所需权限串,自动并入角色编辑器(见 04 §5)。

## 3. 自动 CRUD(来自 Extension 注册)

每个 kind 默认生成:

```
GET    /api/v1/{plural}          list      (公开对象默认仅 published)
POST   /api/v1/{plural}          create    (需 {kind}:manage)
GET    /api/v1/{plural}/{name}   get
PUT    /api/v1/{plural}/{name}   update
DELETE /api/v1/{plural}/{name}   delete
```

请求/响应体即 Extension 三段结构:

```json
{ "apiVersion": "penblog.dev/v1", "kind": "Post",
  "metadata": { "name": "hello-world", "uid": "...", "creationTimestamp": "..." },
  "spec": { "title": "Hello", "content": "...", "status": "draft", "categories": ["tech"] },
  "status": { "permalink": "/2026/10/hello-world", "commentsCount": 3, "lastModifyTime": "..." } }
```

- `status` 只读,由服务端计算;客户端 PUT 只接受 `spec/metadata`。

## 4. 列表查询规范(首版必须有)

```
GET /api/v1/posts?page=2&size=20                 # offset 分页(管理后台用)
GET /api/v1/posts?cursor=eyJpZCI6...&size=20     # cursor 分页(无限滚动/外部同步用)
    &filter[status]=published&filter[category]=tech&filter[created_at][gte]=2026-01-01
    &sort=-published_at,title                     # 多列排序,"-" 前缀降序
    &fields=metadata.name,spec.title,status.permalink   # 稀疏字段
```

响应元信息:

```json
{ "items": [...], "page": 2, "size": 20, "total": 137,
  "nextCursor": "eyJ..." }
```

- filter 运算符:`[gte]/[lte]/[in]`(逗号列表)/ 精确匹配;字段白名单校验,未知 filter 返回 400。
- cursor 为不透明 base64,服务端编码 `(sort值, id)`;size 上限 100。

## 5. 统一错误格式

```json
{ "error": { "code": "validation_failed", "message": "title 不能为空",
  "details": [ { "field": "spec.title", "reason": "required" } ],
  "requestId": "req_9f3a..." } }
```

| HTTP | code 示例 |
|---|---|
| 400 | validation_failed / bad_cursor |
| 401 | unauthenticated |
| 403 | permission_denied |
| 404 | not_found |
| 409 | conflict / duplicate_event_id |
| 429 | rate_limited(带 `Retry-After`) |
| 500 | internal(只回 requestId,细节进日志) |

## 6. 写操作安全

- **幂等键**:创建类 POST 支持 `Idempotency-Key` 头,重复提交返回首次结果(缓存 24h)。
- **乐观并发**:PUT 携带 `metadata.resourceVersion`,不匹配返回 409 —— 防外部程序覆盖。
- 批量端点:`POST /api/v1/posts:batch`(数组,≤100 条,逐项返回结果)。

## 7. OpenAPI 导出

- 注册表驱动:Extension kind + 路由声明 → 运行时生成 `GET /api/v1/openapi.json`(AIP 风格,x-* 扩展标注权限串)。
- M5 提供静态导出与文档站渲染;插件注册的端点自动并入。

## 8. Webhook 出站(事件推送给外部程序)

```
POST /api/v1/webhooks
{ "url": "https://example.com/hook", "events": ["post.published", "comment.submitted"],
  "secret": "whsec_..." }
```

- 投递走通知管线 webhook 渠道(05 §6):HMAC-SHA256 签名、指数退避、死信。
- 事件体:`{ "id", "event", "occurredAt", "data": <Extension 快照> }`。

## 9. 公开与私有边界

| 面 | 默认可见性 |
|---|---|
| 内容类 posts/categories/tags/pages/comments | 已发布内容公开读(未认证) |
| 用户 | 仅公开 `displayName/avatar`(详情需 `user:read`) |
| Options/Theme/Plugin/Notification 管理面 | 全部需认证+对应权限 |
| Secret | 任何 API 永不返回(02 §6) |

## 10. 限流

- 匿名:按 IP,60 req/min;认证:按令牌,600 req/min;PAT 可声明更高配额(管理员授予)。
- 响应头 `X-RateLimit-Remaining/Reset`;429 统一错误格式。
