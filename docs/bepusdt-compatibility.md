# Bepusdt 原协议与真实交易回归

第二十九轮在既有控制器及八项测试上继续补齐真实订单、卡密和销量事务。三个回归先失败：关闭渠道漏掉已付款回执，已签名 fiat=USD 仍发货，验签后 pay_check 改变仍发货。现在使用原始 JSON、独立协议客户端和共同锁内收款守卫，三个分支均通过。

## 保留的接口契约

merchant_id 仍是密钥，merchant_pem 是自托管基础 URL 或完整 /api/v1/order/create-transaction，pay_check 作为原 trade_type 发送。基础 URL 可以有子路径，完整 URL 及尾部斜杠不会重复追加接口。建单仍发送 order_id、原数字 amount、fiat=CNY、trade_type、notify_url、redirect_url、signature；商户配置和路由没有新增字段。

原签名按 ASCII 字段名排序并拼接 key=value，追加密钥后 MD5。仅跳过 signature、null、空字符串；数值零、字符串零和 true/false 字面布尔参与签名，未知平坦元数据也参与。没有套用旧 Epusdt 的数值零过滤规则。[独立字节回归](../tests/Unit/Payments/BepusdtClientTest.php)保存从旧控制器 Reflection 验证的黄金向量 f217e9057f979dda820585c409dca27b，同时核对独立拼接字符串。

amount 是商品法币金额；actual_amount 是加密币数量。status=2 或字符串 2 才履约。签名、订单和金额正确的 1/3 生命周期通知只应答，不发货。旧回调没有 fiat/trade_type 回显时沿用原 CNY 兼容；有回显时必须分别等于 CNY 和配置方法，包括空/null 回显也失败。token 是收款地址，不当币种使用。

冻结公开源码 [v1.20.4 提交 8b8bddc](https://github.com/v03413/BEpusdt/blob/8b8bddc99e93b0039a39737e3effc7fed3d59e04/app/web/notify/notify.go#L20) 的回调模型将 amount 标为 CNY，且没有 fiat/trade_type。[Bepusdt 付款分支](https://github.com/v03413/BEpusdt/blob/8b8bddc99e93b0039a39737e3effc7fed3d59e04/app/web/notify/notify.go#L148)严格要求正文 ok；同文件上方的包含 success 判断属于另一种回调，不能混用。因此将原 Bepusdt success 改为精确 ok，避免已发货但服务端判失败反复通知。生命周期仍返回 HTTP 200 和 ok。当前[回调文档](https://github.com/v03413/BEpusdt/blob/main/docs/notify/readme.md)及[API 文档](https://github.com/v03413/BEpusdt/blob/main/docs/api/api.md)仅作版本演进参考，本阶段没有引入独占地址零金额或新的状态模式。

## 请求和履约

[控制器](../app/Http/Controllers/Pay/BepusdtController.php)只解析 application/json 当前原始 body，复用有界 JSON 解析器；原始空白/null/布尔不受中间件清洗，query 不覆盖签名字段。64 KiB/128 个平坦字段限制、重复键与转义别名、嵌套结构、畸形 JSON 失败；数值 amount 的指数或超过分的精度在浮点转换前失败。

已付款通过 Pay::find 读取当前渠道，包括 is_open=0。receiptGuard 在真实履约事务里核对订单号、价格、pay_id、pay_check、route、三项商户配置及开启的现代 Stripe/PayPal 绑定；对重复交易仅发一次，对不同交易失败，字符串零交易号保留。已过期付款记录异常供人工处理而不消费库存。返回页仍只跳订单详情，缺少订单号 HTTP 400。

[独立客户端](../app/Payments/BepusdtClient.php)直接将验证后的十进制字符串编码为原 JSON number；serialize_precision=17 仍输出 amount:7.01。Guzzle 仅继承测试 handler，TLS 验证、连接 3 秒/总请求及读 10 秒、禁止重定向和自动 POST 重试、响应 64 KiB 上限与流关闭明确。服务响应要求 HTTP 200/status_code 为整数或字符串 200；可选订单、金额、法币、方法回显必须关联。自托管 HTTP/HTTPS、独立支付页 origin 以及原返回/支付页查询参数保留。API 基础 URL 的查询参数、用户信息、片段、控制字符和畸形百分号失败。异常只记录类别，不把原请求、商户密钥或提供商正文交给浏览器。

## 验证与重放

本轮新增 271 项：客户端 204、真实路由 67。原有八项测试保留并适配明确 ok 应答、真实 Pay 读取和 callable guard；它们的 mock 委派验证不替代新增交易测试。

| 最终验证 | 测试 / 断言 | 证据 |
| --- | --- | --- |
| PHP 7.4 根全量 | 2100 / 16764 | [最终报告](../tests/.runtime/bepusdt-root-final-29.xml) |
| Laravel 12 / PHP 8.2 全量，含六项 SMTP | 2106 / 16306 | [最终报告](../tests/.runtime/laravel12/bepusdt-full-final-29.xml) |
| Bepusdt 原生交易，双框架 | 67 / 641 | 两份最终报告中的 BepusdtRouteTest |
| 独立客户端，双 PHP | 204 / 815 | 两份最终报告及 PHP 8.2 独立支付目录 |
| 原有八项测试 | 根 8 / 93；候选 8 / 53 | 两份最终报告中的 BepusdtPaymentTest |
| PHP 8.2 全部独立支付 | 1360 / 9744 | CLI exit 0，旧 Pay2 Collection/Config 弃用提示保留 |

    & tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini vendor/bin/phpunit --do-not-cache-result --log-junit tests/.runtime/bepusdt-root-final-29.xml
    php -d extension=gd tests/Compatibility/Laravel12/vendor/bin/phpunit -c tests/Compatibility/Laravel12/phpunit-core.xml --do-not-cache-result --log-junit tests/.runtime/laravel12/bepusdt-full-final-29.xml
    php vendor/bin/phpunit --do-not-cache-result tests/Unit/Payments

真实路由覆盖关闭/过期付款、重复/不同/零交易号、七种验签后上下文变化、现代绑定、旧无回显与有效回显、法币/金额/状态错误、原 JSON 污染及大量转义字段、返回无发货。十二种结账样例是四个原样方法字符串乘三种接口 URL 形态，不代表这些币种的真实商户可用性。实际 HTTP 位于事务外，只有提供商 HTTP 与通知被替换。五个实际变更文件 PHP 7.4 lint 通过；两个全量无排除通过。预期 Injected queue failure / Injected notification failure 是故障注入。

独立审查未发现新增可复现 P1/P2，并以纯内存分支核对价格/渠道/方法/凭据/route；探索任务提前失败但留下版本提示，主代理随后读取实际公开源码和注解标签对应的固定提交补证，没有将任务失败当作验收成功。

## 剩余验收

本轮未改依赖/锁/系统配置/数据库迁移，既有根 112 包、候选 132 包的平台检查可复用。没有访问商户凭据、真实付款、SMTP、生产数据库或部署/提交代码。原建单入口重复访问仍按旧流程提交；服务端建单唯一性、商户 TLS/版本及实收通知需账户验收。

后续[独立 MySQL 验收](mysql-concurrency.md)已在根与候选框架各通过36组/85 worker及七份前向迁移，包含15组共同receiptGuard和3组旧v1预留/查询恢复。HTTP签名/路由沿用既有SQLite原生证据；数据库竞态直接执行交易服务。真实部署表结构、后台/资产浏览器、现代测试工具链、正式 PHP/框架及容器/worker/scheduler仍待完成。根框架保留，整体目标继续 active。
