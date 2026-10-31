# 11 Markdown 内容与编辑器设计

> 补充设计(联网调研):内容页与后台编辑器统一以 Markdown 为唯一正文载体。
> 调研来源:GFM 规范、Ghost/Typecho/Halo/WordPress 编辑器实践、league/commonmark 安全指南。

## 1. 语法基线:CommonMark + GFM 扩展 + 脚注

业界事实标准(Ghost 直接按 CommonMark 解析;Typecho 社区插件与 league/commonmark 均走 GFM 配置;GitHub 实际支持脚注)。

| 语法 | 记法 | 状态 |
|---|---|---|
| 标题 | `#`–`######` | 基线 |
| 粗体/斜体/行内码 | `**x**` `*x*` `` `x` `` | 基线 |
| 链接 | `[t](url)`、`<https://…>`、裸 URL 自动链接 | 基线+GFM |
| 图片 | `![alt](src)` | 基线(alt 必填红线) |
| 无序/有序列表 | `-`/`*`、`1.` | 基线(仅平铺,嵌套 M6) |
| 任务列表 | `- [ ]` / `- [x]` | GFM |
| 删除线 | `~~x~~` | GFM |
| 表格 | `\|a\|b\|` + 对齐行 | GFM(包 `.table-wrap`) |
| 围栏代码 | ` ```lang ` | 基线(语言类名输出) |
| 引用/分割线 | `>`、`---` | 基线 |
| 脚注 | `[^1]` + `[^1]: 定义` | GitHub 扩展 |
| 摘要分隔符 | `<!--more-->` 的 Markdown 等价:`<!--break-->` 行 | 自研扩展 |

**明确不做**(调研结论):front-matter(标题/摘要/分类走独立字段,与 Ghost/Halo 一致)、数学公式(仅未来客户端 KaTeX 占位渲染)、mermaid(前端执行 JS,风险高,默认关闭)、原始 HTML(禁止)。

## 2. 安全策略(渲染管线红线)

来源:league/commonmark Security、CommonMark 社区共识。

1. **先转义后结构替换**(等价 `html_input=escape`):任何原始 HTML 不进入输出——PenBlog 现有管线方向正确,保持。
2. **URL 协议白名单**:`http/https/mailto` + 站内相对路径;`javascript:`/`data:` 一律降级为纯文本。
3. 输入上限:正文 ≤ 200KB;行内替换避免回溯爆炸(嵌套量词禁用)。
4. 脚注/锚点 id 经白名单正则消毒,防注入。

## 3. 编辑器规格(后台)

形态:原生 textarea + 工具栏 + 分屏预览(渐进增强,禁 JS 时纯表单照常可用)。对标 Ghost(自动解析+快捷键)与 Typecho(textarea+工具栏+预览页签)。

### 3.1 工具栏按钮
粗体 / 斜体 / 删除线 / 标题(循环 H1-H3)/ 无序列表 / 有序列表 / 任务项 / 引用 / 行内码 / 代码块 / 链接 / 图片 / 表格 / 分割线 / 脚注 / 摘要分隔符。

### 3.2 快捷键(对标 Ghost/Typecho)
`Ctrl+B` 粗体、`Ctrl+I` 斜体、`Ctrl+K` 链接、`Ctrl+U` 下划线位(改删除线 `Ctrl+D`)、`Ctrl+S` 保存草稿、`Ctrl+Shift+P` 切换预览、`Tab` 焦点陷阱外不劫持。

### 3.3 预览
- 桌面(≥1024px):左右分屏,输入 300ms 防抖后 `POST /admin/preview`(服务端权威渲染,与前台同一管线,杜绝"编辑器好看、发布后变样")。
- 窄屏:编辑/预览整页切换。

### 3.4 草稿与并发
- **本地保险**:停止输入 5s 写 `localStorage`(键 `pb-draft:{slug|new}`);进入编辑器若有更新副本则提示恢复(对标 Halo 插件实践)。
- **服务端保存**:显式 Ctrl+S / 保存按钮走表单提交(乐观并发 `resourceVersion`,06 §6);409 冲突 → 顶部 danger 横幅 + 本地副本已保存提示,绝不静默丢弃(Ghost 的教训)。
- 不做 30s 自动提交服务端(Halo 因性能废弃过自动保存;个人博客场景手动+本地保险足够)。
- 字数统计(中文字+英文词)实时显示。

### 3.5 图片上传
本期仅支持外链 URL 与粘贴 URL 插入;上传端点(媒体库)排期 M5(与通知/附件管线合并设计)。

## 4. 实现映射

| 件 | 位置 |
|---|---|
| 语法扩展 | `src/Support/Markdown.php`(GFM 表格/任务/删除线/自动链接/脚注/图片/有序列表) |
| 编辑器路由 | `ThemeRoutes`:`GET/POST /admin/posts/new`、`GET/POST /admin/posts/{slug}/edit`、`POST /admin/preview` |
| 模板 | `themes/paper/templates/admin_editor.php` |
| 编辑器 JS | `themes/paper/assets/editor.js`(工具栏包裹/快捷键/防抖预览/localStorage 草稿/字数) |
| 语法测试 | `tests/Unit/MarkdownTest.php` 扩展 |
