# 旧 Epusdt / TokenPay 协议与交易验收

本阶段保留两个已配置的旧 MD5 接口、商户字段和十二种支付入口，使用当前请求的原始 JSON 完成验签。四个真实已签名回归先复现未付或过期仍返回 ok 并履约，再以已付款状态判断修复。根项目仍是 Laravel 6；Laravel 12 是隔离候选。

## 固定的旧协议

| 网关 | 商户配置 | 建单与金额 | 已付款回调 |
| --- | --- | --- | --- |
| Epusdt | merchant_id 为 API 密钥，merchant_pem 为完整接口 URL | 原 amount/order_id/redirect_url/notify_url/signature；amount 是人民币 JSON number | 原 status=2、order_id、amount、trade_id；纯文本 ok |
| TokenPay | merchant_id 为原加密币种，merchant_key 为 API 密钥，merchant_pem 为基础 URL | 原 ActualAmount/OutOrderId/OrderUserKey/Currency/RedirectUrl/NotifyUrl/Signature；基础路径追加 /CreateOrder | 原 Status=1、BaseCurrency=CNY、Currency 等于配置币种、ActualAmount/Id；纯文本 ok |

Epusdt 以人民币 amount 核对商品价，actual_amount 是 USDT 数量。TokenPay 以法币 ActualAmount 核对商品价；Amount、PayAmount 是加密币数量，绝不替代商品价格。TokenPay 回调缺少或不匹配法币/币种时停止履约。数值或字符串形式的正常状态值保留；布尔值、未付、过期和无效交易号失败。

签名排序仍是 ASCII key=value 拼接后追加原密钥并计算 MD5。Epusdt 明确保存旧 PHP 7.4 函数对数值零、false、null、空串的省略规则，字符串零仍参与签名；TokenPay 保存旧 PHP 函数对空串/null 的 key= 和原布尔 cast。未知平坦字段参与原签名。新版本的 HMAC、忽略空值或布尔 true/false 算法没有混入这两个客户端。

金额从已验证的两位小数字符串生成原 JSON number，不经浮点 JSON 编码；7.01 在 serialize_precision=17 时仍是 7.01。TokenPay 缺少邮箱或仅含空白时，以订单号作 OrderUserKey，已有邮箱保持原值。原服务端要求用户标识非空；订单号也符合官方为每单分配地址的用法。独立客户端的空用户向量只记录旧签名字节，生产控制器会先补齐该字段。

## 请求和履约边界

仅解析 application/json 的当前原始 body，不合并 query 或依赖 TrimStrings 后的字段。请求上限 64 KiB、最多 128 个平坦字段；重复键、Unicode 转义别名重复、嵌套结构和畸形 JSON 失败。原数值金额的指数/超过分的精度在 JSON 浮点转换前失败，金额字符串也由精确金额验证器检查。

独立审查复现了约 8 KiB 合法长字符串触发 PCRE JIT 栈限制的问题。五个长字符串回归先全部失败，分块且占有式匹配修复后通过；包括完整 64 KiB body、两万个引号/反斜杠和混合转义。六个真实签名长字段通知也可完成付款，JIT 开启/关闭及 PHP 7.4/8.2 都通过。

已验签回执使用 Pay::find 恢复已关闭渠道的已付款订单，并进入共同 receiptGuard。真实订单/渠道锁内重新核对价格、pay_id、pay_check、route、三项商户配置及现代 Stripe/PayPal 绑定。重复回执只发一次，不同交易号失败；已过期订单记录异常供人工处理，保留已释放库存。同步返回直接跳订单详情，不休眠且不发货。

建单 HTTP 使用独立 Guzzle 配置，仅继承测试 handler；TLS 验证、连接/总请求/读超时明确，禁用重定向和自动 POST 重试，64 KiB 响应流在成功/失败均关闭。错误不回传商户密钥、请求或提供商原文。自托管 HTTP/HTTPS 与独立 WebSiteUrl 沿用原契约，支付页可以与 API 不同 origin；不接受 URL 用户信息、片段、控制字符或畸形百分号编码。

## 验证与重放

本轮新增 331 项：Epusdt 客户端 101、TokenPay 客户端 119、JSON 解析/金额编码 32、原生交易路由 79。客户端有独立旧签名 oracle 和黄金向量；原生路由使用真实订单、卡密和销量事务，只替换通知及提供商 HTTP。十二种原入口、缺邮箱建单、法币/币种、关闭/过期付款、七类验签后上下文变化、现代绑定、污染/长字段/数值精度和原始空白均覆盖。

| 最终验证 | 测试 / 断言 | 报告 |
| --- | --- | --- |
| PHP 7.4 根全量 | 1829 / 15308 | tests/.runtime/crypto-gateways-root-final-28.xml |
| PHP 8.2 Laravel 12 全量，含六项现代 SMTP | 1835 / 14850 | tests/.runtime/laravel12/crypto-gateways-full-final-28.xml |
| PHP 8.2 根独立支付 | 1156 / 8929 | CLI exit 0；既有 Pay2 Collection/Config 弃用提示保留 |
| 两框架原生数字货币路由 | 79 / 804 | 两个最终全量报告 |
| JSON 解析/编码，双 PHP 与 JIT 开/关 | 32 / 92 | 独立命令及两个最终全量报告 |

    & tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini vendor/bin/phpunit --do-not-cache-result --log-junit tests/.runtime/crypto-gateways-root-final-28.xml
    php -d extension=gd tests/Compatibility/Laravel12/vendor/bin/phpunit -c tests/Compatibility/Laravel12/phpunit-core.xml --do-not-cache-result --log-junit tests/.runtime/laravel12/crypto-gateways-full-final-28.xml
    php vendor/bin/phpunit --do-not-cache-result tests/Unit/Payments
    php -d pcre.jit=0 vendor/bin/phpunit --do-not-cache-result tests/Unit/Payments/JsonNotificationTest.php

最初全量 1818/15269 和 1824/14811 只作为修复前阶段记录；最后报告已由加入长字段回归并修复后的全量覆盖。预期 Injected queue failure / Injected notification failure 是故障注入。

## 协议证据和剩余验收

- [Epusdt 冻结旧 Go 签名](https://github.com/assimon/epusdt/blob/20c576b9bca3060b23e011ff69633f32af85f737/src/util/sign/sign.go#L47) 与 [旧响应模型](https://github.com/assimon/epusdt/blob/20c576b9bca3060b23e011ff69633f32af85f737/src/model/response/order_response.go#L15)：原数值签名用十进制格式，旧回调 amount 为人民币、status=2，成功应答是 ok。
- [TokenPay 冻结旧 wiki](https://github.com/LightCountry/TokenPay/blob/836e94b9f68fbda106cbf8b448d0d2f0120c876e/Wiki/docs.md#L128)、[旧 ToDic](https://github.com/LightCountry/TokenPay/blob/836e94b9f68fbda106cbf8b448d0d2f0120c876e/src/TokenPay/Extensions/ObjectExtension.cs#L8) 与 [创建模型](https://github.com/LightCountry/TokenPay/blob/836e94b9f68fbda106cbf8b448d0d2f0120c876e/src/TokenPay/Models/CreateOrderViewModel.cs#L17)：回调金额字符串、原空字段签名字节、付款状态和必需用户标识。原创建验签跳过空字段，与空标识的旧 PHP 建单存在矛盾；控制器以非空标识闭合该分支。
- [当前 Epusdt GMPay 文档](https://epusdt.com/guide/integration/gmpay) 已描述新版接口和 HMAC-SHA256，不是本项目旧 MD5 契约；[当前 TokenPay 文档](https://github.com/LightCountry/TokenPay/blob/master/Wiki/docs.md) 的空值/布尔规则也不替换冻结旧算法。两种新版接入需另行版本选择。

没有访问真实付款 API、商户凭据、SMTP 或目标数据库。本轮不新增迁移/持久建单绑定/inbox/退款对账，不修改依赖/锁/系统配置，也没有部署或提交。原建单入口重复访问仍按旧流程提交，服务端唯一性、真实 TLS/商户账户和运行时 Decimal 语义需账户验收。

旧数字货币阶段保留了既有 Bepusdt 控制器/测试，并定位关闭渠道过滤、缺少 receiptGuard 的差异；后续[Bepusdt 阶段](bepusdt-compatibility.md)已用真实交易回归补齐，最新根 2100/16764、候选 2106/16306。随后[MySQL双框架验收](mysql-concurrency.md)各36组/85 worker及七份前向迁移通过，覆盖新共同回执锁和旧v1预留/恢复；真实部署表结构、后台/资产浏览器、现代测试工具链、正式 PHP/框架与容器/worker/scheduler 尚待验收，整体目标继续进行。
