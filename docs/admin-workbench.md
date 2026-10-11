# 管理工作台

后台首页使用独立 Vue 3 工作台，沿用原有 Dcat 登录、会话、CSRF 和角色权限。商品、订单、卡密、优惠券、支付渠道、邮件模板和系统设置仍使用原有业务控制器；新主题同时覆盖后台外壳和这些管理页。

## 页面与操作

以下路径以默认 `admin` 前缀举例，实际链接使用当前后台前缀生成。未单独配置后台域名时，工作台接口和导航使用相对路径，沿用浏览器当前的域名、协议和端口，兼容 HTTPS 反向代理与 HTTP 端口直连；显式配置后台域名时继续使用该域名。

| 页面 | 地址 | 内容与可执行操作 |
| --- | --- | --- |
| 经营概览 | `/admin`、`/admin/operations/overview` | 今日订单、待支付、异常订单、销售额、订单搜索；关闭符合原有过期规则的订单。 |
| Stripe 对账 | `/admin/operations/stripe` | 本地支付绑定、对账和事件处理记录；重置失败记录，执行一批到期对账。 |
| PayPal 对账 | `/admin/operations/paypal` | 本地订单绑定、捕获对账和事件处理记录；重置失败记录，执行一批到期对账。 |
| 通知投递 | `/admin/operations/notifications` | Webhook、付款邮件、人工订单状态邮件；筛选失败及处理中记录，重新排队失败通知，发布一批通知。 |
| 冻结报价 | `/admin/operations/quotes` | 订单的人民币与美元金额、报价来源、汇率日期、冻结时间；分页搜索，只读。 |
| 运行状态 | `/admin/operations/runtime` | 当前数据库、缓存、会话、队列配置，功能开关与数据库安装状态；查看本队列失败任务并按记录重试。 |

所有业务写入均为 POST。重试调用原有对账、通知和 Laravel 队列重试入口，保留原有租约、幂等和履约检查。失败队列重试按规范化任务编号加缓存锁，并在数据库行锁内覆盖原生入队及移除失败记录过程，防止同时人工操作重复入队。通知租约仍有效时人工重试返回状态冲突；已经成功或已重置的记录不会再次重置。单次批量对账最多 25 条，通知发布最多 100 条，过期订单处理最多 100 条。冻结报价不从管理页面重新计算或覆盖。

读接口为 `operations/{section}/data`，操作接口为 `operations/{section}/action`。搜索和状态参数由服务端校验，默认每页 20 条，最多 50 条。页面和 API 仅返回明示的业务字段，不返回支付凭据、客户端密钥、通知内容、卡密、购买密码、队列 payload 或原始异常。

## 权限

每个模块分别授予查看与操作权限，HTTP 方法应明确设为 GET 或 POST。授予查看权限不会自动授予重试或批量处理权限。可使用模块页面路径 `/operations/stripe` 等，或下面的命名路由规则；后台权限编辑器按原有约定填写路径、命名路由与方法。

| 模块 | 查看命名路由 | 操作命名路由 |
| --- | --- | --- |
| 经营概览 | `operations.overview.page` | `operations.overview.operate` |
| Stripe | `operations.stripe.page` | `operations.stripe.operate` |
| PayPal | `operations.paypal.page` | `operations.paypal.operate` |
| 通知 | `operations.notifications.page` | `operations.notifications.operate` |
| 报价 | `operations.quotes.page` | 无写操作 |
| 运行状态 | `operations.runtime.page` | `operations.runtime.operate` |

表格使用权限编辑器支持的不带后台应用前缀的命名模式，实际路由由当前 `admin_route_name` 补齐前缀。模块 API 必须同时具备模块权限；只授予 data/action 分发器地址不构成模块授权。侧栏和工作台链接按权限过滤，最终写接口仍独立校验登录、方法、CSRF 和记录状态。

## 配置与安装

工作台根据现有部署配置与真实数据库结构显示状态。缺少可选迁移时显示待配置；功能关闭时仍可查看已安装表中的历史记录，业务操作被禁用。页面不会修改 `.env`、自动安装迁移或启用商户渠道。现有支付及通知迁移、开关配置见支付和通知文档。

队列、缓存、会话驱动是当前应用配置，页面读取不等于后台 worker 或调度器正在运行。数据库队列积压仅在对应连接、表和队列可读取时展示。Redis 等队列仍由已有运维工具核实积压和消费者健康。

## 构建与验证

在 `frontend` 执行 `npm run build:admin` 生成 `public/assets/admin` 独立资源，保留商店的 `public/assets/modern`。工作台通过真实 Dcat 页面加载，支持首页和页面导航重新挂载。

后端聚焦回归为 `AdminOperationsTest` 与 `NotificationOutboxTest`。浏览器场景使用 `tests/admin-fixture.php --init-isolated-admin --operations`，HTTP 仅监听 `127.0.0.1:8203`；`node tests/browser-admin-operations.cjs` 检查实际登录、六页 API、分页搜索、权限、CSRF、租约、操作状态、导航及移动布局。该场景使用独立 SQLite、文件会话、运行目录和合成支付客户端。
