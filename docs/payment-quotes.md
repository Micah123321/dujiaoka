# 持久订单报价与收款核对

可选报价冻结已接入现有PayPal和Stripe路由，解决收银展示与收费跨越汇率缓存TTL后重新定价的问题。PAYMENT_QUOTE_FREEZE默认false；关闭时直接使用原汇率服务，不查询新表，旧店铺更新无需立即增加表。报价冻结批次保留原支付接口；随后[Stripe22及唯一PaymentIntent](stripe-payment-intents.md)已提供可选迁移，[PayPal Orders V2](paypal-orders.md)及独立[签名后台对账](paypal-webhooks.md)也已提供默认关闭的路径。

## 启用与兼容

先在目标店铺的独立迁移阶段安装新增表，再启用开关并按现有部署约定刷新配置缓存。以下仅记录部署所需命令；本轮只对SQLite内存库和固定MySQL夹具执行迁移，没有连接店铺数据库或更改实际环境设置。

~~~text
php artisan migrate --path=database/migrations/2026_01_01_000003_create_payment_quotes_table.php
PAYMENT_QUOTE_FREEZE=true
~~~

启用前已经打开、尚未留下冻结报价的旧PayPal/Stripe收银页应重新进入收银台。回调只读取已有报价，缺报价不会通过实时取价补造收款依据。关闭开关恢复兼容路径，保留报价表；启用模式进行中的交易应在切换前核对，避免失去当前报价和回执约束。down()会删除报价数据，不作为普通回退步骤。

## 报价冻结

- payment_quotes使用InnoDB，唯一键为order_id/pay_id/currency。保存人民币原价、美元应收金额、报价来源、原始十进制分子/分母、参考报价日期和记录时间，不保存商户凭据、卡号或token。
- 初次操作短事务锁订单，检查订单号、当前渠道、状态和原价，已有报价按原比例重算核对后复用。无报价时退出事务获取完整报价，随后再锁单复核；若另一请求已保存报价，返回其金额。HTTP不占用订单事务，外层事务中的冷报价获取也会停止。
- PayPal使用Frankfurter，Stripe使用原招行来源；同单切换支付渠道会分别冻结，切回原渠道复用原金额。不同来源的参考价格保持分离。快照不自动改价，订单原价变化、渠道快照不符、数据损坏均停止付款流程。
- 订单只允许在待支付状态生成或重用收银报价；已完成/过期回调仍可核对既有报价，最终状态与过期付款交由原OrderProcessService的锁和事务规则处理。冻结报价不增加新的到期任务，也不取代既有到期任务。

## 服务端收款核对

PayPal在execute前校验服务器Payment的ID、sale意图、单一transaction、invoice_number、USD与冻结金额。execute后或已有approved结果必须含单一completed Sale，金额和parent_payment一致，才使用真实Sale ID完结人民币订单。取消或缺参数直接返回订单详情，修复原缺少return而继续调用SDK的问题。

Stripe银行卡收费使用冻结美分，核对返回Charge的status/paid/captured、未退款、币种与金额，再使用真实Charge ID完结人民币订单。启用模式只显示本地公开故障提示，不返回SDK异常原文。

Stripe微信和支付宝Source必须匹配订单owner.name、来源ID、type、对应币种及应收金额，校验通过后才发起Charge。微信收USD、支付宝收CNY，完结订单都使用原人民币金额，修正旧return分支直接按美元金额完结的问题。consumed状态单独不作为付款成功依据，仍需取得已成功收款的Charge。

银行卡token和Source按订单、渠道及来源哈希生成稳定Idempotency-Key，同一尝试用相同参数重试。Source重试可取回首次Charge结果，超出订单创建后23小时停止重放，已支付订单不再次发起Source收费。[Stripe官方规则](https://docs.stripe.com/api/idempotent_requests)说明键最少保留24小时、参数必须相同，包含错误结果也会被缓存；这里没有把这个期限视为永久保证。[PayPal旧API执行与Sale定义](https://developer.paypal.com/api/deprecated/payments/v1/payment-execute)用于核对原SDK协议；现已由[SDK无关v1适配](paypal-v1-compatibility.md)实现同样的端点和回执要求。

## 验证与剩余迁移

PHP7.4实际完整238 tests/1536 assertions通过；新增服务与native路由分别35/176、31/297，独立凭据模块12/21。PHP8.2限定独立支付模块48/247通过，未启动旧Laravel6。普通filter发现全部Feature数据提供器会触发Laravel6 ArrayAccess弃用信息，因此现代运行时验证直接指定独立模块目录，不修改vendor或压制旧框架问题。

真实MySQL8.4.11、REPEATABLE-READ并发验收9组/31个独立worker通过。新增两个进程都在事务外取得不同模拟报价，初次订单锁均观察到LOCK WAIT，最终仅一行快照，两进程都返回赢家金额2.00 USD。全部九张夹具表为InnoDB，三份前向迁移执行通过；实例已正常停止。详情见[并发验收](mysql-concurrency.md)。SDK与汇率HTTP均使用本地替身，没有真实收费。

单独启用报价冻结时，不同token或Source同时提交仍可能产生多笔实际收费；后续新增STRIPE_PAYMENT_INTENTS开关通过订单唯一PaymentIntent替代旧收费端点，详见[完整启用要求与边界](stripe-payment-intents.md)。新收银可通过Stripe.js处理付款验证；后续[Stripe签名对账](stripe-webhooks.md)及[PayPal唯一Order](paypal-orders.md)/[签名对账](paypal-webhooks.md)已提供额外迁移和独立开关，支持已验证候选的长时间未知ID恢复。既有默认兼容模式仍未获得上述新约束。
