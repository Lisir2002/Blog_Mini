# 08 UI/UX 设计规范(细化版)

> 本篇从"设计方向"细化为**可执行规范**:完整设计令牌、版式阶梯、布局栅格、页面线框、组件像素规范、暗色对照与动效约束。默认主题 "Paper(编辑部)" 按本规范实现;所有数值均为令牌默认值,主题可通过 theme.json 覆盖。

## 1. 设计原则(不可妥协项)

1. **内容优先**:UI 退后,阅读体验是第一指标;装饰性元素(阴影/渐变/圆角堆砌)默认不用。
2. **令牌驱动**:组件禁止硬编码颜色/字号/间距,一切视觉属性来自 CSS 变量(由 theme.json 编译注入)。
3. **三态完备**:任何数据区域必须有 加载/空/错误 三态设计,不允许白屏或裸文字。
4. **渐进增强**:前台核心阅读零 JS;JS 只做增强(目录、通知轮询、抽屉)。
5. **可访问即底线**:键盘可达、对比度达标、语义 landmark,不算"额外工作量"。

## 2. 设计令牌(默认主题 Paper)

### 2.1 颜色

| 令牌 | Light | Dark | 用途 |
|---|---|---|---|
| --color-bg | `#FAFAF8` | `#141413` | 页面底色(纸感微暖) |
| --color-surface | `#FFFFFF` | `#1E1E1D` | 卡片/输入区表面 |
| --color-surface-muted | `#F2F1EE` | `#262624` | 次级表面(表头、骨架屏) |
| --color-border | `#E4E2DD` | `#3A3A37` | 描边(卡片用描边不用阴影) |
| --color-text | `#1C1B1A` | `#EDECE9` | 正文 |
| --color-text-secondary | `#57534E` | `#B5B1A9` | 摘要、次要文字 |
| --color-text-muted | `#8A857D` | `#807C74` | meta、占位符(≥4.5:1 on bg) |
| --color-accent | `#4F46E5` | `#A5A0FF` | 链接、主按钮、焦点、选中 |
| --color-accent-hover | `#4338CA` | `#B8B4FF` | 链接/按钮 hover |
| --color-on-accent | `#FFFFFF` | `#141413` | accent 上的文字 |
| --color-success | `#15803D` | `#4ADE80` | 发布成功、已送达 |
| --color-warning | `#B45309` | `#FBBF24` | 待审核、限流提示 |
| --color-danger | `#B91C1C` | `#F87171` | 删除、投递失败、校验错误 |

规则:
- 语义色只用于**状态编码**(通知状态机、表单校验、审核队列),不得当装饰色。
- accent 由主题令牌替换(换强调色=换全站气质),其余中性色成对保留。
- 链接默认无下划线,`:hover` 出现下划线;正文内链接始终带下划线(text-underline-offset: 2px)。

### 2.2 字体与版式阶梯

字体栈:
- 正文 `--font-body`: `-apple-system, "Segoe UI", "PingFang SC", "Noto Sans SC", sans-serif`
- 标题 `--font-heading`: `"Source Han Serif SC", "Noto Serif SC", Georgia, serif`(默认主题衬线标题,可令牌切换)
- 代码 `--font-mono`: `"SF Mono", "Cascadia Code", Consolas, monospace`

| 层级 | font-size | line-height | weight | 其他 |
|---|---|---|---|---|
| Display(文章大标题) | `clamp(1.75rem, 1.4rem + 1.8vw, 2.5rem)` | 1.2 | 700 | tracking -0.02em |
| H2(区块标题) | `clamp(1.375rem, 1.2rem + 0.8vw, 1.75rem)` | 1.3 | 700 | margin-block: 2em .6em |
| H3 | `1.25rem` | 1.4 | 600 | — |
| Body | `clamp(1rem, .96rem + .2vw, 1.0625rem)` | **1.7** | 400 | measure 68ch |
| Small/meta | `.875rem` | 1.5 | 400 | 用 text-secondary |
| Code | `.9375em` | 1.6 | — | 行内 code 加 surface-muted 底 |

正文细节:
- 段落间距 `--space-base`(16px),首行不缩进;中文标点悬挂,中西文间隙用 `text-autospace`(渐进增强)。
- 引用块:左侧 3px accent 竖线 + surface-muted 底;hr 用 border 色居中短段(64px)。
- 代码块:`overflow-x:auto` 容器 + 语言标签 + 复制按钮(hover 出现),行号可选。
- 图片:`width/height` 必填防 CLS;正文图默认圆角 `--radius-md`,点击放大(增强)。

### 2.3 间距 / 圆角 / 阴影 / z-index / 动效

```
--space-2xs:4px   --space-xs:8px    --space-sm:12px  --space-md:16px
--space-lg:24px   --space-xl:32px   --space-2xl:48px --space-3xl:64px  --space-4xl:96px
--radius-sm:4px  --radius-md:8px  --radius-lg:12px  --radius-full:9999px
--shadow-sm: 0 1px 2px rgb(0 0 0/.06)          /* 仅下拉、悬浮卡片 */
--shadow-md: 0 4px 16px rgb(0 0 0/.10)         /* 仅 modal、抽屉 */
--z-dropdown:10  --z-sticky:20  --z-overlay:50  --z-toast:100
--dur-fast:120ms  --dur-base:180ms  --ease:cubic-bezier(.2,0,0,1)
```

- 卡片一律描边 + radius-md,不用阴影;阴影只留给浮层。
- 动效只作用于 `transform/opacity`;hover 120ms、展开 180ms;`prefers-reduced-motion` 下全部禁用。

## 3. 全端自适应布局系统(双端任意分辨率/比例,防错乱为第一目标)

### 3.1 三层自适应策略(原则)

1. **流式优先,断点只管结构切换**:绝大多数尺寸变化由连续技术消化(`clamp()` 字号/内边距、`minmax()`/`auto-fill` 网格、`fr`/`%`/`ch` 列宽),media query 仅在页面骨架需要换结构时出现(侧栏↔抽屉、单列↔双列);组件形态(卡片、评论项、下拉)一律用**容器查询**,组件对所在容器宽度负责,与视口解耦——换屏/换容器都不错乱。
2. **禁止固定像素宽度**:布局中不允许出现可能大于视口的写死宽度;内容列用 `min(100% - 2*pad, 上限值)` 思路(即 `max-width` + 百分比 padding)。
3. **超宽不拉伸**:容器 `max-width` 封顶(前台 1080px/正文 72ch),2560/3440 宽屏多出的是留白,列表网格靠 `auto-fill` 自然增列。

### 3.2 防错乱硬规则(每条都是布局崩坏的常见根因,代码审查红线)

| 规则 | 防的是什么 |
|---|---|
| grid/flex 子项一律 `min-width: 0` | 长文本/长 URL 撑爆容器,整页横向溢出 |
| 正文 `overflow-wrap: break-word`,URL/代码 `overflow-wrap: anywhere` | 无空格长串撑破 68ch 正文栏 |
| 表格/代码块/图片墙包 `overflow-x: auto` 滚动容器 | 宽内容顶破页面骨架 |
| 图片 `max-width:100%; height:auto` + `width/height` 属性 | 大图溢出 + 加载位移(CLS) |
| 视口高度只用 `min-height: 100dvh`,禁 `100vh` | 移动端地址栏收展导致的底部遮挡/跳动 |
| 固定元素(页头/抽屉/toast)加 `env(safe-area-inset-*)` | 刘海屏/圆角屏内容被切 |
| hover 效果包在 `@media (hover: hover)` 内 | 触屏设备点按后样式"粘住"错乱 |
| `100% + padding` 场景统一 `box-sizing: border-box` 全局 | 经典宽度超算 |
| 横向滚动区加 `scroll-snap` 而非换行挤压(标签条等) | 小屏下 chip 群折行打乱节奏 |

### 3.3 设备矩阵(断点 360/768/1024/1440 为骨架切换基准)

| 场景 | 宽度 | 前台行为 | 后台行为 |
|---|---|---|---|
| 小手机 | 320–375 | 单列;页边距 16px;导航汉堡;卡片强制单列紧凑形态(`@container (max-width: 360px)`) | 顶栏 + 底部抽屉导航;表格降级为卡片式行 |
| 大手机 | 376–600 | 单列;卡片 `auto-fill minmax(320px,1fr)` 自然 1–2 列 | 同上 |
| 竖屏平板/小笔记本 | 601–900 | 单列内容 + 底部侧栏(可选) | 侧栏折叠 64px 图标栏,悬停/点击展开 |
| 横屏平板/桌面 | 901–1280 | 2 列卡片网格;目录侧注出现 | 240px 侧栏 + 内容 max 1200px |
| 标准桌面 | 1281–1600 | 同上,容器封顶居中 | 同上 |
| 超宽(含 21:9) | >1600 | 容器封顶,网格可 3–4 列 | 内容区封顶,两侧留白 |

### 3.4 宽高比与方向适配(横竖屏切换不错乱)

- **横屏矮手机**(如 844×390,`@media (orientation: landscape) and (max-height: 500px)`):hero 压缩为单行、sticky 页头降高至 48px、文章目录改下拉、评论区表单列化——保证首屏可见正文标题。
- **4:3 平板**(iPad 系):正文 68ch 与卡片网格天然适配,无需专门断点;后台走 768–1023 档。
- 竖↔横切换由流式布局连续过渡,禁止依赖 JS 读 `window.innerWidth` 做布局分支(仅允许用于懒加载触发)。
- 字体缩放:全站相对单位 `rem`,尊重用户在系统/浏览器的字号设置;`clamp()` 上限防止放大溢出。

### 3.5 验收标准(纳入 CI 截图矩阵)

- 分辨率矩阵:`320×568 / 375×667 / 390×844 / 768×1024 / 844×390(横屏) / 1024×768 / 1440×900 / 2560×1440`。
- 每页(首页/列表/文章/归档/搜索/后台仪表盘/列表页/编辑器)在每个尺寸断言:
  1. `document.documentElement.scrollWidth <= innerWidth`(**无横向滚动条=不错乱的硬指标**);
  2. 无元素超出视口边界(溢出检测脚本);
  3. 文字不重叠、图片不变形。
- 前台容器:`max-width:1080px; padding-inline: clamp(16px, 4vw, 32px)`;文章容器 `max-width: 72ch`;卡片网格 `repeat(auto-fill, minmax(320px, 1fr))`,gap `--space-lg`。
- 页头 sticky(高 64px,`background: color-mix(in srgb, var(--color-bg) 88%, transparent)` + blur 增强),滚动 80px 后加 border-bottom。

## 4. 页面设计规范(线框 + 要点)

### 4.1 首页 / 归档列表

```
┌ header: logo · 主导航 · 暗色开关 · 搜索 ┐
├ Hero(仅首页一次): 站点名 + 一句话简介        ┤
├ 精选文章(可选,1 大卡)                        │
├ ┌卡片┐ ┌卡片┐ ┌卡片┐  ← auto-fill 网格        │
│ │封面 │ │封面 │ │     (无封面则纯文字卡)      │
│ │标题 │ │标题 │ │标题                          │
│ │摘要2行││摘要 │ │meta                         │
│ │meta  │ └────┘ └────┘                          │
├ 分页: ← 1 2 3 … → (offset);无限滚动为增强选项 ┤
└ footer: 站点信息 · 链接 · RSS · 备案            ┘
```

- 卡片形态:封面 16:9 顶部铺满;标题 2 行截断;摘要 2 行;meta 行=日期·阅读时长·分类;整卡可点(标题链接承载 href,卡面 `:has()` 变色)。
- 卡片内边距 `--space-md`,封面与文字间 `--space-sm`。

### 4.2 文章页

```
标题(Display) → meta 行(作者头像+名·日期·分类·预计阅读)
封面(全栏宽)
正文 68ch ────────────── 侧注:目录(≥3 个 H2 时,sticky)
标签 chips
── 版权声明 / 上一篇·下一篇(两栏)
评论区: 计数 + 表单 + 列表(两级嵌套)
```

- 目录:桌面右侧浮动(正文列内右溢),移动端折叠为"目录"按钮 → 抽屉;当前节高亮 accent。
- 阅读进度条:页头底部 2px accent,`transform:scaleX()`。
- 评论表单:昵称/邮箱(未登录时)+ 正文 textarea(min 5 行);提交按钮右侧;校验行内提示 danger 色。

### 4.3 独立页面 / 404 / 搜索

- 独立页面复用文章页版式(去评论可选)。
- 404:一句人话 + 返回首页按钮 + 最近 5 篇文章链接(不摆烂)。
- 搜索:输入即结果(防抖 300ms),命中词 `<mark>` 高亮 surface-muted 底。

### 4.4 通知中心(呼应 05)

- 铃铛下拉:最近 10 条,按分类图标区分(comment/system/content/digest),底部"全部已读 / 查看全部"。
- 独立页:tab = 全部 | 四分类;行结构 = 分类图标 + 标题 + 相对时间 + 未读点(accent);点击即已读并跳转;左滑/按钮归档。
- 偏好设置页:按分类分组卡片,每组=渠道 checkbox + 静默时段 + 继承标记("使用系统默认"开关)。

### 4.5 管理后台

外壳:顶栏 56px(面包屑+搜索+用户菜单)+ 侧栏 240px(08 §6 IA 七区)。

- **仪表盘**:指标条(文章/评论待审/未读通知/存储 4 块)+ 最近评论队列 + 最近发布列表;卡片描边式,数字用 Display 层级缩半。
- **列表页统一模板**(文章/页面/评论/用户/通知轨迹):
  ```
  工具条: [搜索] [filter 下拉=状态/分类] [批量操作]      [+ 新建](右)
  表格行高 48px: 勾选 | 主列(标题+次行 meta) | 状态徽章 | 分类 | 日期 | ⋯菜单
  底部: 每页 N 条 · ← 页码 →   (与 API page/size 一致)
  ```
  状态徽章:草稿=muted、待审=warning、已发布=success、失败=danger(语义色唯一合法用途)。
- **文章编辑器**:顶栏(标题输入+状态+发布) / 左 Markdown 编辑 / 右设置面板(分类、标签、摘要、slug、可见性、发布时间);工具栏粗体/斜体/链接/图片/代码/引用;`Ctrl+S` 草稿自动保存(60s 节流),冲突提示走 resourceVersion(06 §6)。
- **设置页**:左侧锚点 tab + 右侧表单分区;每区独立保存;声明式渲染器输出(03 §5)。

## 5. 组件规范(像素级)

| 组件 | 规范 |
|---|---|
| 按钮 | 高 40px(小 32px/大 48px),padding-inline 16px,radius-sm;主=accent 底 on-accent 字;次=描边 surface;危险=描边 danger,hover 才填充实色;禁用 opacity 50% 且去 hover |
| 输入框 | 高 40px,`--space-sm` 内边距,border 1px;focus: accent 边框 + 2px accent/30% 外圈(`:focus-visible` 同理);错误: danger 边框 + 下方 12px 提示;label 常驻上方 14px |
| 标签 chip | 高 24px,radius-full,padding-inline 10px,surface-muted 底 secondary 字;hover accent 描边;选中=accent 底白字 |
| 导航 | 水平项高 40px 文本 15px;当前项 accent 字 + 下划线 2px;移动端汉堡→全屏抽屉,焦点陷阱 |
| 分页 | 按钮 36px 方形,当前页 accent 描边;省略号不可点 |
| 下拉/菜单 | surface 底 + shadow-sm + radius-md;项高 36px;键盘 ↑↓/Enter/Esc;右对齐弹出防溢出视口 |
| Tabs | 下划线式(后台分区)/ 分段控件式(前台筛选);选中 accent 下划线 2px 过渡 120ms |
| Toast | 右下,宽 max 360px,自动 4s 消失,hover 暂停;成功/危险带语义左边框 3px |
| Modal | 遮罩 overlay 50% 黑;面板 max-w 560px radius-lg shadow-md;焦点陷阱+Esc 关闭;危险操作确认必须显示对象名 |
| 骨架屏 | surface-muted 底 + opacity 脉冲(仅 reduced-motion 外);形状贴合真实卡片 |
| 空态 | 图标 48px muted + 一句说明 + 主操作按钮(如"写第一篇文章") |
| 头像 | 24/32/48 三档,圆形,无图用首字母 accent/10% 底 |
| 开关/复选 | 点击区 ≥44px;开关 40×22px;复选 18px radius-sm |

## 6. 暗色模式与对比度校验

- 策略:theme.json `darkMode.strategy = media | class`;class 模式由页头开关写 `localStorage` + `<html class>`。
- 切换零闪烁:内联 4 行引导脚本在首帧前设置 class。
- 校验:构建期脚本遍历令牌对(text/bg、accent/bg、on-accent/accent),低于 4.5:1(正文)/3:1(大字、图标)即 CI 告警;主题上传时同样校验 theme.json 令牌。

## 7. 无障碍清单(验收项)

- [ ] skip-link 到主内容;landmark:header/nav/main/article/aside/footer
- [ ] 全站 `:focus-visible` 2px accent 外圈,禁止 outline:none 裸奔
- [ ] 表单 label 绑定 + `aria-describedby` 错误关联;键盘完成发帖/评论全流程
- [ ] 图片 alt 必填(装饰图 `alt=""`);图标按钮带 `aria-label`
- [ ] 颜色不是唯一信息通道(徽章带文字,未读点带 aria-label)
- [ ] Tab 顺序=视觉顺序;抽屉/模态焦点陷阱与返回焦点

## 8. 邮件模板(通知 email 渠道)

- 600px 单栏,table 布局(客户端兼容);内联样式由通知模板渲染器从同一令牌表生成(颜色/字号)。
- 结构:站点名页眉 → 分类徽章 → 标题(Display 缩半) → 正文 → 主按钮(accent) → 页脚(偏好管理链接+退订)。
- 暗色:仅依赖 `prefers-color-scheme` 媒体查询做表面色切换,正文两模式同字色策略。

## 9. 性能预算(前台)

| 项 | 预算 |
|---|---|
| CSS | 单文件 ≤ 30KB gzip(令牌+关键样式) |
| JS | 全站 ≤ 20KB,defer;无框架 |
| 字体 | 系统栈默认;webfont 必须 `font-display: swap` 且子集化 |
| CLS | < 0.05(图片宽高属性强制) |
| LCP | 首屏封面/标题 < 1.2s(本地缓存+懒加载非首屏图) |

## 10. 落地顺序(与里程碑对齐)

1. **M2**:令牌编译管线(theme.json→CSS 变量)+ Paper 主题骨架(首页/文章/后台表格)+ 流式布局基座(§3.2 硬规则进 CSS reset/工具层)
2. **M3**:组件库补全(下拉/模态/骨架/空态)+ 容器查询卡片形态 + 暗色开关 + §3.5 溢出检测脚本进 CI
3. **M4**:通知中心 UI(铃铛/独立页/偏好页)
4. **M6 打磨清单**:垂直节奏微调、邮件模板、对比度 CI 脚本、编辑器双栏细节、动效审计、全分辨率矩阵截图回归
