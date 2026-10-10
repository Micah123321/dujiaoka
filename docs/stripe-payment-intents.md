# Stripe 订单唯一 PaymentIntent

Stripe SDK已从7.84.0实际升级到22.0.0，新付款流程通过PaymentIntent及Payment Element替代直接创建Charge/Source。新流程默认关闭；同一个订单仅保留一条持久绑定，首次选择的支付渠道与币种保持不变。按[官方PaymentIntent契约](https://docs.stripe.com/api/payment_intents)，多个付款尝试复用同一对象，最多产生一次成功收费。本地测试证明绑定与请求一致性，未发起真实Stripe收费。

## 启用与切换

新SDK的真实发行包要求PHP>=7.4，根清单已提高最低版本至7.4；PHP7.4是兼容回归环境，实际框架和目标平台迁移仍待后续。Composer仅更新Stripe一包，默认旧入口的Charge/Source请求显式恢复账户默认API版本，避免SDK22自动改变旧账户请求。真实SDK Curl在本地回环HTTP上已确认旧请求不发送Stripe-Version；新StripeClient单独固定SDK22的2026-09-30.endive，显式官方API基址，连接3秒/总计10秒、0自动重试，SDK异常原文和链不向用户返回。

本轮未修改实际环境或连接店铺数据库。启用前在独立部署阶段安装两张新增表，按项目部署方式刷新配置缓存：

~~~text
php artisan migrate --path=database/migrations/2026_01_01_000003_create_payment_quotes_table.php
php artisan migrate --path=database/migrations/2026_01_01_000004_create_stripe_payment_intents_table.php
PAYMENT_QUOTE_FREEZE=true
STRIPE_PAYMENT_INTENTS=true
~~~

已有旧Charge/Source收银页应结束或关闭后再切换。启用后旧stripe/charge、check、return_url返回409，不再收费或完结；旧付款的恢复必须先核对，避免和新Intent交叉收费。关闭开关会恢复旧路径，应先结束已发client_secret的付款流程并核对账户记录；关闭开关或删除数据库行不会使已经发出的远端Intent失效。迁移down()删除绑定，普通回退保留表。应用的订单号访问约定仍被复用，client_secret只由POST响应返回对应订单，禁止把它写入日志、缓存或共享链接。

## 持久绑定与故障恢复

stripe_payment_intents以order_id唯一，保存pay_id、原CNY金额、实际币种和分单位金额、随机reference、账户配置SHA256指纹及provider_id，不保存卡号、token、client_secret、商户密钥原文。首次收银提交之前仍可换渠道；绑定一旦创建，网关选择通过锁定同一订单读取绑定，跨渠道切换停止。USD允许card/wechat_pay，CNY允许alipay。首次进入后换币种停止，取消的Intent也不自动创建另一份。

短事务锁订单和渠道、创建或复用绑定，然后退出事务访问Stripe。未知provider_id使用同一reference与完全相同创建参数、confirm=false；服务返回后再锁单复核并绑定ID。两进程共用参数和键，在Stripe幂等契约下取得同一个对象。创建断连保留绑定和键，不生成新键；绑定创建起23小时后仍未知ID时停止重放，避免[最少24小时保留的幂等键](https://docs.stripe.com/api/idempotent_requests)被清理后产生另一个对象，通过后续已签名事件的服务端reference恢复或人工核对，详见[恢复说明](stripe-webhooks.md)。已绑定ID不受该期限限制，始终按数据库ID retrieve，不信任浏览器传入的Intent ID或redirect_status。

每次API前后核对订单号、支付渠道、原CNY金额、账户指纹和绑定字段。创建要求待支付且渠道开放；既有收款状态核对允许已支付/过期订单及关闭的渠道，但配置指纹必须仍匹配。USD回执从冻结报价核对实际金额，CNY使用原人民币。只有succeeded、实际amount_received足额、expanded latest_charge对应Intent、成功且已捕获未退款，才以真实ChargeID完结原CNY订单。失败/处理中/需验证/仅授权不发货；过期已收款由原OrderProcessService转异常供人工处理。

## 收银与确认

原pay/stripe/{payway}/{orderSN}在启用时渲染统一响应式收银页。用户选择人民币支付宝或冻结美元的银行卡/微信，提交后锁定选择，用真实Stripe.js Payment Element和confirmPayment完成验证及付款。人民币路径不依赖美元汇率；美元报价故障时暂时禁用美元选项，仍可选支付宝，已绑定CNY页面不再获取汇率。

POST /pay/stripe/intent负责创建/复用，POST /pay/stripe/intent/status只retrieve并核对；两个端点额外启用CSRF，独立于旧pay/*回调免验证规则。服务返回只含必要字段，Cache-Control no-store/private；页面不嵌入client_secret或私钥，并使用no-referrer。返回URL忽略客户端支付成功声明，按持久ID查询。前端仅在服务端确认后跳订单详情，处理中的付款有限退避查询、隐藏标签停止、最多24轮并保留手动查询。

## 实际验证与后续

- 最新PHP7.4完整327 tests/3003 assertions通过；新服务54/905，新原生路由6/91，旧支付与新路由组合44/475。新路由实际经过SDK22编码与CSRF，缺token返回419、伪造Intent/status不起效，渠道关闭后的已付收款仍可核对。
- 最新PHP8.2独立支付模块58 tests/542 assertions通过（含签名模块），现代客户端测试证明不继承旧全局API基址、密钥或版本，创建有稳定键、retrieve扩展最新Charge、错误信息脱敏。旧Laravel6未在PHP8.2启动。
- MySQL8.4.11/REPEATABLE-READ/InnoDB最新真实12组竞争/37独立worker、五份前向迁移通过。Intent组连接51/50均进入LOCK WAIT并持续500ms，两进程请求在事务层数0产生、键及参数完全一致，最终仅一行绑定且provider_id一致。远端API用替身验证请求，不把替身回执视为真实Stripe服务器测试。证据见[MySQL验收](mysql-concurrency.md)，实例已正常停止。
- Chrome/Playwright加载实际Blade输出，桌面1440和移动390无溢出或JS错误，付款组件由本地Stripe替身提供。覆盖币种在请求期间锁定、CSRF、付款确认、processing不跳页、服务端succeeded后跳转；截图位于tests/.runtime/stripe-intent-desktop.png和stripe-intent-mobile.png。没有加载真实支付组件或进行真实银行卡验证。

后续已新增[可选签名Webhook与后台对账](stripe-webhooks.md)，需额外迁移和STRIPE_RECONCILIATION开关才能无人值守确认。仅开启Intent时仍依靠回调/页面查询，用户关页且不再返回时缺少独立触发。支付Intent并不自动随订单过期取消，晚付款按既有规则供人工处理；远端取消与意图恢复属于下一批。PayPal已提供默认关闭的[唯一Orders/Capture](paypal-orders.md)及[签名对账](paypal-webhooks.md)，目前通过Guzzle官方协议适配，旧SDK仅保留默认兼容路径，官方新SDK仍受根框架依赖约束。根Laravel6、Dcat及邮件/代理系统升级与实际新框架启动仍未完成。
