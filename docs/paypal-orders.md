# PayPal Orders V2 与订单唯一捕获

本批添加默认关闭的PAYPAL_ORDERS_V2路径，保留原/pay/paypal/{payway}/{orderSN}入口并按开关分流。现代路径在本地保存订单唯一绑定、冻结USD/CNY和账户指纹，分别保存创建/捕获请求键；服务端GET Order及独立GET Capture后，才复用原CNY卡密/销量事务。实际店铺环境、数据库、PayPal账户和收费均未在本批修改。

## 协议与依赖

依据[官方Orders V2](https://developer.paypal.com/docs/api/orders/v2/)及[幂等说明](https://developer.paypal.com/api/rest/reference/idempotency)，创建和捕获POST使用不同PayPal-Request-Id；相同操作重试使用原键和原参数，代表性响应由Prefer:return=representation请求。官方默认保存键6小时，本实现从首次本地创建/捕获尝试开始保守限制5小时；没有假设账户已申请72小时。

官方paypal/paypal-server-sdk2.4.0声明PHP^7.2||^8.0，但apimatic/core~0.3.17要求symfony/http-foundation^5.4||^6||^7||^8，与本应用Laravel6的4.x依赖冲突。初始Orders V2批次实际预演后撤回根SDK声明。当前仍以Guzzle7调用官方V2协议；隔离Laravel12已安装ServerSDK2.4并通过常规全量测试，但未据此切换根框架。第二十四轮已移除旧REST SDK，默认v1模式改由[原生兼容协议](paypal-v1-compatibility.md)保留旧链接、GET恢复与执行回执校验。

OAuth只使用官方api-m.paypal.com或api-m.sandbox.paypal.com，Basic客户端凭据交换令牌，内存客户端缓存且不落库/日志。请求TLS核验、禁止重定向、连接3秒/总计10秒；不自动重试POST。OAuth和所有订单/捕获HTTP均在交易事务外。异常统一为固定消息，不包含供应商正文/凭据/异常链。

## 启用与旧店铺切换

先完成[冻结报价](payment-quotes.md)000003迁移，再追加000006：

~~~text
php artisan migrate --path=database/migrations/2026_01_01_000006_create_paypal_orders_table.php
PAYMENT_QUOTE_FREEZE=true
PAYPAL_ORDERS_V2=true
PAYPAL_ORDERS_MODE=live
~~~

测试环境将mode改为sandbox并使用该模式对应账户；配置缓存和反向代理需保证实际域名生成的return/cancel URL稳定。使用店铺当前可启动的PHP执行命令（本批Laravel6在PHP7.4验收，PHP8.2仅独立支付模块）。各PayPal渠道仍merchant_key=Client ID、merchant_pem=Client Secret，本批额外要求原merchant_id=PayPal收款商户ID（13位大写字母数字，非邮箱），并在创建时明确指定payee。live为默认，不能用新模式替换已绑定记录的模式/商户；变化会停止自动付款。没有新增或读取实际凭据原值。

开关关闭时无需新表，原流程仍工作；启用时入口走V2，原v1 return_url和未验签notify_url返回409，停用v1执行且不记录通知正文。启用前暂停新付款并核查全部旧v1待完成/不确定付款；本批不会把旧paymentId自动转换为Orders V2，给同订单另建对象可能造成第二笔收费。退款/人工清账及旧对象归档按实际历史处理后再切换。

恢复旧应用时保留paypal_orders/payment_quotes及已保存键/ID，不在已有现代对象待确认时直接关闭开关恢复v1创建；现代退款/自动作废及晚付款核查尚待后续。迁移down会删除该表及恢复依据，属于有数据的回退操作，不作为普通回滚步骤。

## 创建、审批与回调

paypal_orders.order_id全局唯一，保存原CNY、冻结USD、pay_id、reference、独立32hex create_key/capture_key、账户/模式指纹、固定return/cancel URL和provider/capture ID。只创建一个CAPTURE意图PurchaseUnit，reference_id/custom_id绑定本地reference，invoice_id绑定订单号，USD amount和payee绑定已保存值，数字商品NO_SHIPPING。网络断连重复进入时复用原参数/key，不重新换键或创建另一对象；未知创建已满5小时停止POST留人工核查，已知provider_id始终GET不限该窗口。

只跳转对应模式官方www.paypal.com或www.sandbox.paypal.com HTTPS/checkoutnow?token=已保存ID，拒绝相似域名、额外参数、用户名、端口或不同token。新返回/cancel URL在动态路由前注册：

~~~text
/pay/paypal/v2/return?orderid=ORDER_SN&state=STATE_HMAC
/pay/paypal/v2/cancel?orderid=ORDER_SN&state=STATE_HMAC
~~~

state是APP_KEY对订单号/渠道ID/动作的HMAC；跨cancel/return更换动作或伪造状态不会调用API。浏览器token/PayerID/status不认定付款，现代回调仅使用本地唯一绑定。APP_KEY及原生成URL需要保留至对象清账，不用会话存储才能在异域跳转后恢复。成功路由到原订单详情且no-store；配置/校验/网络失败仅固定503。cancel只GET核对已存在对象，不自动取消或捕获APPROVED。

再次进入原入口，已绑定对象只GET；如果已经APPROVED且没有审批链接，则继续捕获而不新建。缺返回/首次捕获断连时重新进入入口或原已签名返回URL可恢复。当前未新增页面框架、付款前端SDK或生效设置，不声称真实PayPal网页已人工支付验收。

## 捕获与实收核对

APPROVED且用户返回/reentry或启用可选后台对账时，订单仍WAIT且渠道open才可领取90秒随机token捕获租约；first capture_attempted_at持久，后续同键重放限5小时。占用时只返回当前状态，不做第二个POST；失败仍保留capture_key/首次时间，旧worker只清理匹配token与期限的租约。跨进程/租约接管不换键，创建键与捕获键各自唯一。PayPal同时收到同键可能让其中一个请求失败，本地保留绑定等待后续GET。

捕获响应不直接发货：重新GET Order，检查唯一PurchaseUnit/原ID/invoice/custom/reference/USD/payee，然后必须唯一COMPLETED且final_capture=true的Capture，再独立GET /v2/payments/captures/{id}确认金额USD与supplementary_data.related_ids.order_id。仅Order COMPLETED不是已实收证据，官方也要求检查captures.status；PENDING保持不发货等待结算；合法唯一final/full金额PENDING返回CAPTURE_PENDING供后台继续轮询且不计失败。declined/少付/退款状态/多次或部分捕获停止完结。

保存capture ID后，OrderProcessService实际发货事务内再次锁Order/Pay/绑定，重核账户、模式、金额、冻结报价及字段，持锁到库存/订单提交。已关闭渠道允许查询之前已收费对象，禁止新capture。过期订单晚支付转异常保留真实capture ID，不消费已释放卡密或返还券；重复同Capture保持一次卡密和销量。

PaymentGatewayLock使启用的Stripe与PayPal绑定共享同一订单行互斥；绑定存在就停止切到其他渠道，新供应商API之前也检查。已存在绑定按主键锁；不存在绑定不作FOR UPDATE空索引读取，避免MySQL REPEATABLE-READ的间隙锁跨不同订单/供应商形成循环。各创建路径按持有的订单行序列化，独立订单仍可并行。

## 恢复边界与验证

仅开启Orders V2时由浏览器返回/reentry驱动恢复；后续[可选签名事件与后台对账](paypal-webhooks.md)已交付，需000007及PAYPAL_RECONCILIATION额外启用才覆盖永久关页/遗漏通知，并支持已验证候选恢复未知创建ID。未知创建超过5小时不盲目重放、搜索无结果不代表未创建。capture尝试超5小时但GET显示COMPLETED仍核对发货；若仍APPROVED则人工核查，不新换捕获键。未实现自动void/refund，晚付款走异常订单流程。

初始Orders V2批次PHP7.4全量367tests/3652assertions通过，新增服务24/496、真实Guzzle原生路由/卡密事务10/79、独立编码与回执6/74；PHP8.2支付模块64/616通过，旧框架尚未现代启动。测试覆盖固定端点OAuth/正文编码/无自动POST重试、USD/Payee/实收关联、断连键恢复/5h截止、90s租约/接管、I/O期间变动、实际事务guard、伪造返回/token、取消、gateway重进、重复发货、过期/关渠道、旧回调409、default-off无需新表和双向渠道互斥。

初始Orders V2批次MySQL8.4.11/REPEATABLE-READ/InnoDB十三张夹具表及六份前向迁移、16组竞争45进程通过。两创建请求相同参数/key且一行绑定，两capture竞争仅一个POST替身/一次真实卡密/销量，选择竞争另一渠道被挡；不同订单跨供应商强制交错最初复现真实间隙死锁，修复后重放通过。证据见[并发验收](mysql-concurrency.md)。独立只读审查未发现可确认P1，父后续锁测试额外发现并修复该数据库死锁；未连接真实PayPal或店铺数据库。
