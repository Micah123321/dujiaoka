# BEpusdt 支付

使用 BEpusdt 原生收银台接口，买家选择 USDT 后可在收银台自行选择网络，复用商城订单与发货流程，无需新增依赖。固定网络支付仍使用 create-transaction 接口。

## 后台配置

在「支付渠道」找到默认关闭的 `BEpusdt[USDT-TRC20]` 模板，编辑对接令牌和服务地址后启用。新安装会自动导入此模板；已有站点执行 `php artisan migrate --force` 后补齐，迁移保留已配置或已删除的同标识渠道，重复执行不会新增重复记录。

Docker Compose 部署使用：

```sh
docker compose --env-file .env.compose exec --user application web php artisan migrate --force
```

若需其他币种或网络，也可以按以下字段新增记录：

| 字段 | 填写内容 |
| --- | --- |
| 支付名称 | USDT |
| 商户 ID | BEpusdt 后台「系统管理 → 基本设置 → API 设置」的对接令牌（AUTH_TOKEN） |
| 商户 KEY | 留空 |
| 商户密钥 | BEpusdt 地址，例如 https://pay.example.com，也支持完整的 /api/v1/order/create-transaction 地址 |
| 支付标识 | USDT（自选网络）；需要固定网络时填 usdt.trc20、usdt.erc20 等 |
| 支付场景 | 通用 |
| 支付方式 | 跳转 |
| 支付处理路由 | pay/bepusdt |
| 是否启用 | 是 |

支付标识 `USDT`（不区分大小写）调用 `/api/v1/order/create-order`，传入 `currencies=USDT`，不指定网络。买家在 BEpusdt 收银台选择 BSC、Polygon、Tron 等已启用的网络。BEpusdt 版本须支持 create-order 接口，并配置对应网络的 USDT 钱包。服务地址可填域名或任一完整创建接口地址，程序会按模式选择接口。固定网络标识仍调用 create-transaction。商城金额按 CNY 传递，链上应付数量及汇率由 BEpusdt 计算。每个支付标识应唯一；配置多条通道时使用不同交易类型。BEpusdt 需已配置对应网络的钱包。

## 支付失败排查

启用自选网络：更新代码后，在支付渠道选择「USDT · 自选网络」（标识 USDT），保存并新建订单测试。旧的 TRC20 模板可直接编辑，无需为每条链新增渠道。修改本地代码不会自动更新远程站点。

应用日志中的 `BEpusdt checkout failed.` 包含订单号和脱敏 `stage`：1 为本地参数/配置，2 为连接/TLS/超时，3 为 HTTP 状态或响应大小，4 为 JSON/网关业务拒绝，5 为返回订单字段不匹配。日志不记录对接令牌、签名或原始网关响应。

## 回调与验证

系统自动提交以下地址（站点地址须为外部可访问的 HTTPS 地址）：

- 异步通知：`https://商城域名/pay/bepusdt/notify_url`，接收 JSON POST，校验签名、订单所属通道与法币金额。
- 同步跳转：`https://商城域名/pay/bepusdt/return_url?order_id=订单号`，只展示订单，不改变支付状态。

只有 `status=2` 触发现有订单完成流程，成功应答为纯文本 `ok`。`status=1/3` 的合法通知只确认接收。错误签名、金额不符、未知订单及处理失败返回 `fail`。重复通知复用订单服务的事务和幂等保护；本地已过期订单的到账按现有逻辑记为异常订单，人工核对，不重新消耗已释放库存。

上线时检查商城生成的回调域名、HTTPS 反向代理配置，以及 BEpusdt 的支付有效期与商城订单有效期。若启用了路由缓存，更新后执行 `php artisan route:clear`。

测试命令：`php vendor/bin/phpunit tests/Feature/BepusdtPaymentTest.php`。正式根现为 PHP^8.4 / Laravel13；本机验收使用已有 PHP8.5.11 运行时：`& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter BepusdtPaymentTest`。自动化使用模拟网关，不调用真实支付服务。实际启用后用小额订单验证跳转、到账、回调、发货和重复回调。

协议参考：https://github.com/v03413/BEpusdt/blob/main/docs/api/api.md
