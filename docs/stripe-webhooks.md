# Stripe 签名事件与后台对账

可选STRIPE_RECONCILIATION补全订单唯一PaymentIntent的无人值守确认。签名回调先写入持久收件表，后台按Stripe当前对象重新核对，复用原CNY完结和库存事务。用户关闭付款页面、回调遗漏或临时网络失败时，已知provider_id仍由定时任务核对。全部默认关闭，实际环境/店铺数据库和Stripe端点未在本轮修改；启用步骤属于后续部署。

## 安装与渠道设置

先满足[唯一付款意图启用要求](stripe-payment-intents.md)，保留报价表与付款意图表，再执行本批新增迁移并刷新配置缓存：

~~~text
php artisan migrate --path=database/migrations/2026_01_01_000005_create_stripe_reconciliation_tables.php
PAYMENT_QUOTE_FREEZE=true
STRIPE_PAYMENT_INTENTS=true
STRIPE_RECONCILIATION=true
~~~

Stripe渠道原merchant_id存pk_*、merchant_pem存sk_*/rk_*，本批将原未用于Stripe的merchant_key定义为该渠道回调端点的whsec_*签名密钥。继续使用现有后台字段，不新增实际密钥或读取店铺配置原值。每个渠道配置独立端点：

~~~text
POST https://SHOP_HOST/pay/stripe/webhook/PAY_ID
~~~

仅用于Stripe自身账户的v1 snapshot事件；Stripe Connect的account事件忽略，SDK v2 thin通知不接受。建议订阅payment_intent.created、succeeded、processing、payment_failed、canceled、amount_capturable_updated、requires_action。created事件可用于断连创建的ID恢复；事件内容的金额、状态、metadata和client_secret都不会直接用于发货。渠道关闭不阻止已经收费对象的核对，软删除渠道、修改账户密钥/模式/订单金额/绑定信息会停止自动完结供核查。

必须由现有部署的cron每分钟用当前店铺可启动的PHP运行php artisan schedule:run。本轮应用使用PHP7.4，PHP8.2仅完成独立模块验收，旧框架启动仍待迁移。Kernel仅在三个开关都打开时注册payments:reconcile-stripe，每次默认处理至多25条事件及25条绑定状态，CLI limit上限100。程序直接执行有限超时的Stripe GET，不依赖队列发布或浏览器，数据库租约覆盖多个调度进程。任务结束存在待重试或新人工项时退出1；仅报告数量/错误类别，不打印支付密钥、原始事件、签名或供应商异常。历史人工项应另外查看两张表的failed_at/last_error，命令数字是本轮结果，不代表历史积压已清零。

## 签名、去重及确认

[Stripe官方Webhook要求](https://docs.stripe.com/webhooks)原始请求体参与SDK验签，容忍本机时间前后300秒；服务器需正常同步时间。正文至多256KiB、签名头至多4KiB，whsec格式/事件结构严格检查；真实SDK22 HMAC验证允许匹配任一v1签名。HTTP 400表示签名或结构失败，503表示开关/配置/存储暂未就绪，200表示事件已提交或明确不适用/重复；响应no-store且不包含异常原文。失败验签没有数据库写入或Stripe网络请求。

stripe_webhook_events保存pay_id/event_id/provider_id/事件类型/模式/账户SHA256指纹/可处理时间及租约重试字段，唯一约束pay_id+event_id。渠道行锁使相同事件竞争插入保持一行，重复ID对应不同对象/类型/模式/指纹时返回503。保存成功后立刻200，远端查询异步进行；不保存原始事件或client_secret，事件内金额即使错误也不会影响原订单金额。

Stripe重复投递会用新timestamp/signature，重新验签后查询原ID；完成记录阻止重复入库。不同事件乱序或同对象不同ID也通过retrieve读取当前状态，未按event.created排序或回放旧状态。Stripe默认实时模式最多重试三天，手动Resend等恢复方式遵照[官方说明](https://docs.stripe.com/webhooks/process-undelivered-events)；本地落库后的重试由调度器负责。

## 租约与实际发货

stripe_reconciliations以intent_id唯一。新旧已有绑定按批补建状态，即使没有Webhook也可查询。短原子UPDATE领取90秒随机token租约，远端I/O处于事务外；状态/事件写回要求token仍匹配且期限未过，过期工作可被其他进程重新领取。API副作用与本地数据库并非一个分布式事务；本批只有GET，发货由原订单行锁及同ChargeID幂等处理。进程在发货提交后、对账确认前中断时，重新retrieve与完结保持一次卡密/销量变动。

实收succeeded需amount_received及expanded Charge金额/币种/Intent关联成功、已捕获未退款；先复核快照，再在OrderProcessService同一发货事务内重新锁订单/渠道/绑定并核对指纹和字段，持锁到库存/订单提交。金额、pay_id、账户或绑定在初步重核后发生变化也停止发货。processing延后5分钟，其他未终结状态15分钟；succeeded和canceled结束轮询。过期订单已付转异常，保留真实ChargeID，不消费已释放卡密或重新使用返还券，供人工处理。

失败按60秒指数退避，上限1小时；连续10次失败进入failed_at人工暂停。不同事件不能绕过同对象失败退避或清除人工暂停。新事件可提前检查尚未失败的pending状态；已有成功/取消记录直接确认事件。唯一自动恢复特例是provider_id_unknown：已签名事件经远端reference核对并补回ID后可继续查询。其他暂停需修复实际配置/回执原因后显式恢复。

## 创建断连与人工恢复

后台不创建、确认、重建或自动取消付款对象。未知provider_id在23小时以内等待签名事件，超过期限进入provider_id_unknown，不重新使用可能被清除的创建幂等键。收到已验签事件后，以事件ID作为候选GET，远端metadata.binding_reference必须找到该渠道已有绑定，且订单号、金额、币种、方法、模式、账户指纹匹配；再锁单重查才保存provider_id。事件中的metadata不作为恢复依据。已知ID始终按数据库retrieve。

若创建时根本没有发起远端请求，或created事件及所有后续事件均缺失，未知ID仍留人工项；本批不假设Search即时一致，也不根据无结果另建Intent。确认Stripe历史和已有绑定后处理。自动取消过期Intent与退款工作仍待后续，已经发出的client_secret可能晚付款；晚支付沿用异常订单规则。

修复原因后，以本地stripe_payment_intents.id恢复绑定状态，或stripe_webhook_events.id恢复失败收件；这些ID不是Stripe字符串ID：

~~~text
php artisan payments:reconcile-stripe --retry-id=LOCAL_BINDING_ID --limit=25
php artisan payments:reconcile-stripe --retry-event-id=LOCAL_EVENT_ROW_ID --limit=25
php artisan payments:reconcile-stripe --retry-id=LOCAL_BINDING_ID --retry-event-id=LOCAL_EVENT_ROW_ID
~~~

仅failed且租约未被其他进程占用的记录可重置。两类记录同时暂停时合并两个选项；单独重启事件不会清除绑定人工暂停。启用/关闭开关和回滚仍需遵循[付款切换条件](stripe-payment-intents.md)，普通应用回退保留绑定、收件及对账表；迁移down会删除恢复证据。

## 验收

PHP7.4完整327 tests/3003 assertions通过；独立PHP8.2支付模块58/542，真实SDK签名模块8/282通过。新原生路由/真实OrderProcess发货测试覆盖签名错误不写入、持久化失败无200、重复与乱序通知、关页及关闭渠道、临时故障/租约失效、已未知25小时的ID只GET恢复、错误reference/订单/账户不绑定、晚付款不发货、失败暂停及人工恢复。独立审查发现并修复发货事务空窗和事件风暴绕过退避，四类变动交错和10事件突发都有回归。

MySQL8.4.11/REPEATABLE-READ实际12组竞争/37个独立worker及五份前向迁移通过：新增收件连接53/54与对账连接56/57均观察到LOCK WAIT并持续500ms，收件仅一行，对账实际GET替身一次/事务层数0/销量一次。多进程验收结果与锁等待证据见[并发说明](mysql-concurrency.md)。外部HTTP为SDK seam或本地模拟，未触发真实Stripe收费、网络通知或部署；未修改收银UI，沿用上一轮已验收的实际Blade桌面/移动行为。
