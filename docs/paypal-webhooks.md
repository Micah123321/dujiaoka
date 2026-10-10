# PayPal签名事件与后台订单对账

PAYPAL_RECONCILIATION默认false，独立于PAYPAL_ORDERS_V2；需现代Order/冻结报价和000007新表同时就绪才启用。原生签名接收先经官方验签API，再短事务持久规范化字段后200；后台复用已绑定Order/Capture及实际CNY发货guard，处理关闭付款页面、遗漏事件和捕获响应断连。不在回调请求内捕获/发货。

## 官方契约与验签边界

采用[PayPal官方postback验签](https://developer.paypal.com/api/webhooks/v1/verify-webhook-signature-post)：向固定模式api-m.paypal.com或api-m.sandbox.paypal.com的/v1/notifications/verify-webhook-signature发送五个签名header、服务器配置webhook_id和原事件。官方[集成说明](https://developer.paypal.com/api/rest/webhooks/rest)要求webhook_event字节原样，解析再序列化会导致CRC签名失败；客户端嵌入原始正文（含空白/空对象/转义），只单独编码header字段。当前选择供应商在线验签，无额外SDK/证书下载缓存；依赖PayPal API和OAuth可用性。

五header为PAYPAL-TRANSMISSION-ID/TIME/SIG、PAYPAL-CERT-URL和PAYPAL-AUTH-ALGO；只接受SHA256withRSA、HTTPS对应模式官方api或api-m证书路径，不跟随客户端证书URL下载、不跟随重定向。OAuth及验签/Order/Capture网络均在事务外，TLStrue、连接3秒/总10秒（每次请求）。正文上限256KiB、JSON深度64；头和事件ID有长度/字符限制。凭据/OAuth/raw事件/签名/付款人均不写收件表或错误日志。

只有verification_status严格SUCCESS进入收件；FAILURE或本地格式错误400，配置/存储/HTTP故障/畸形供应商确认503。所有响应统一received布尔且no-store/private，不透供应商正文或异常链。验签返回后accept持有同Pay行时重新核对账户/模式指纹和该渠道webhookID映射，远端验证期间发生账户或endpoint变化返回503。close渠道仍接历史事件。

不同于Stripe300秒本地时钟检查，PayPal这里由官方验签服务处理签名，仅检查transmission_time格式；去重和最新GET回执决定操作，不用事件创建时间截断重试。PayPal[通知说明](https://developer.paypal.com/api/rest/webhooks)表示非2xx最多重投25次/3天；本地未持久不能200。官方模拟Webhook不能用于postback验签，真实沙箱订阅/事件仍需后续部署验收，本批只有本地API替身。

## 启用

保持[Orders V2](paypal-orders.md)账户/模式/APP_KEY及原callbackURL，追加迁移后再设置开关：

~~~text
php artisan migrate --path=database/migrations/2026_01_01_000007_create_paypal_reconciliation_tables.php
PAYMENT_QUOTE_FREEZE=true
PAYPAL_ORDERS_V2=true
PAYPAL_ORDERS_MODE=live
PAYPAL_RECONCILIATION=true
PAYPAL_WEBHOOK_IDS='{"7":"WEBHOOK_ID"}'
~~~

7是本地Pay主键的示例，WEBHOOK_ID必须换成该Pay对应REST应用、live/sandbox模式、当前接收URL的真实Webhook订阅ID（不是ClientID、merchantID或浏览器提供值）。空配置保留后台已知对象确认，但签名回调503；配置变更按现有缓存刷新流程执行，本批不改实际环境。无需新后台字段，merchant_key/pem仍ClientID/Secret，merchant_id仍13字符收款MerchantID。

在同REST应用订阅POST /pay/paypal/webhook/PAY_ID，推荐订阅[官方事件](https://developer.paypal.com/api/rest/webhooks/event-names)中的CHECKOUT.ORDER.APPROVED、PAYMENT.CAPTURE.COMPLETED/PENDING/DENIED/DECLINED。普通商户不依赖仅平台/市场支持的CHECKOUT.ORDER.COMPLETED。其他有效签名事件200忽略，无自动退款/争议/撤销订单或反向库存功能；退款和拒付仍人工核查。

000007增加InnoDB paypal_webhook_events、paypal_reconciliations，保留旧表/接口。schedule按三开关条件注册payments:reconcile-paypal，每分钟withoutOverlapping；店铺需按现有部署维护schedule:run，不能把代码注册视为主机调度已启用。命令直执行不依赖持久队列driver：

~~~text
php artisan payments:reconcile-paypal --limit=25
~~~

limit1..100，分别限制事件批次、绑定补种及状态扫描，不是整个命令最多25个网络请求。两个扫描可能服务不同订单；事件等待时间优先再按ID，避免旧暂停事件无限占前25个名额。输出是本次checked/completed/retry/manual/ignored计数，completed指实收确认，过期异常订单仍需人工核查且不代表已经发货；先前人工暂停记录仍需单独核查；有本次retry/manual退出1，命令异常通用消息，关闭开关退出0且不读新表。

## 收件、并发和恢复

pay_id+event_id唯一，只有event_type、候选Order/provider_id、Capture/resource_id、账户指纹和领取/重试元数据。重复来源或类型/ID冲突503；一致重投不再新建事件。事件、状态各90s随机租约，UPDATE条件领取，不凭队列一次性假设。ack仅匹配随机token且期限未过；旧进程不能清理新租约。

先处理due事件，再为已有Order绑定补种状态并查询due对象，因此遗漏Webhook仍能恢复已知ID。服务端GET OrderAPPROVED且本地WAIT/open才调用原samecapturekey捕获，创建POST永不由对账执行。复用原捕获5h限期、重新GETOrder及独立GETCapture校验单次finalCOMPLETED/fullUSD/payee/reference关联；再进入真实订单事务重核，关渠道可确认已有收费但禁止新capture。捕获断连失败先退避，再GET确认，不盲目换键收费。实收已核对的COMPLETED/VOIDED停止状态轮询；OrderCOMPLETED但唯一final/full金额Capture仍PENDING时返回内部CAPTURE_PENDING，保持不发货、不占失败预算，APPROVED/CAPTURE_PENDING后续5分钟、其他等待15分钟，事件可提前检查无失败对象。

失败使用60秒指数退避封顶3600秒，十次连续失败人工暂停；新事件不会绕过共享绑定退避/failed_at，也不会一次事件风暴耗尽同绑定失败预算。事件自身解析错误有独立十次预算。租约忙时事件延期而不计供应商失败；延期按最早等待排序，避免挡住后来unknownOrder恢复。

未知创建ID仅由已验证事件命名候选：Order事件提供resource.id；Capture事件无relatedOrderID时，固定GETCapture提取related_ids.order_id。GETOrder确认唯一PurchaseUnit和服务端reference，查同Pay已有绑定，然后核USD/payee/invoice/custom/CNY/报价，在同Order/Pay/绑定事务再次核对后填唯一provider_id；事件metadata、金额或浏览器值从不建立身份。该流程可恢复25h旧绑定，原create_key/时间保留且不重放create。不匹配reference忽略或错误重试，不把搜索空结果当作未创建。

没有候选且未知创建满5h标记provider_id_unknown人工暂停，不新换键。之后签名候选通过GET确认恢复ID时仅此错误允许自动重开；其他人工暂停需要显式重试。过期订单晚收费走异常订单保留真实交易ID，不消费已释放卡密或返回券。已知ID后台直接查询能补偿关页；永远没有事件、provider_id也丢失时仍需PayPal交易历史人工追查，未实现搜索或自动退款。

## 人工恢复及回退

先修账户/模式/网络/回执问题并核对实际供应商历史；不要直接清租约、改providerID或换创建/捕获键。使用本地paypal_orders.id或paypal_webhook_events.id，不是远端ID：

~~~text
php artisan payments:reconcile-paypal --retry-id=LOCAL_BINDING_ID
php artisan payments:reconcile-paypal --retry-event-id=LOCAL_EVENT_ROW_ID
~~~

只重开已失败且无有效租约的记录，重置失败预算。绑定和事件都暂停时可能需各执行一次；活跃租约或已处理事件保持原状态。仅--retry-id也不能恢复未知远端ID，需已验签事件或人工确认候选流程。

回退先停止新增现代支付，清账已捕获/未知对象，再协调关闭对账或回退应用；保留000006/000007绑定/收件/幂等键及APP_KEY/账户模式/原URL。关闭对账不取消PayPal对象，勿在未确认V2对象仍可收费时重新开启旧v1创建。down删除两新表及恢复依据，不作为有数据的普通回滚。

## 验收证据

PHP7.4完整399tests/4049assertions；PHP8.2独立支付70/687。新增在线验签协议5/64验证实际Guzzle固定端点、原字节、最小字段、无证书请求、FAILURE400类与API故障503类；此替身不等价真实PayPal加密验签。状态13/165覆盖dup/conflict/defaultoff、失效/更换lease、十事件共用失败预算、人工暂停/显式重开、未知ID5h、以及旧暂停批次饿死后来事件的失败→修复重放。补充独立回执测试严格区分PENDING/COMPLETED，并阻断金额/关联/数量/final异常；原生连续十二轮合法PENDING不进入失败暂停，随后实收仅发货一次且零新POST。

原生路由13/161使用真实Guzzle+原Order服务+冻结报价+真实卡密/销量事务，仅通知替身。覆盖持久-before200、签名/配置/存储错误、关页/漏事件、重复乱序、closed/expired晚收费、25h未知ID通过独立Capture关联和GETOrder恢复、wrongpayee/IO期间报价变化、捕获断连后不重复POST、账户/endpoint验签期间变化和条件调度/CLI/defaultoff。

MySQL8.4.11/REPEATABLE-READ十五mc_夹具表、七份前向迁移、18组竞争49独立进程通过。新增收件组两连接先LOCK WAIT，最终一行事件；对账组两连接先争持久租约，赢家在txn0 GET等待另一退出，仅一份OrderGET/独立Capture核对和一次真实卡密销量。完整证据tests/.runtime/mysql-concurrency/run-20261009-193209-e5dfb9e4/summary.json；实例正常停止且确认无13316监听。根Composervalidate通过，旧exactqrcode警告不变；未部署或连接真实PayPal、店铺DB、实际凭据/通知/收费。
