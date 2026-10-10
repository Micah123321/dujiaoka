# 支付汇率与金额换算

本批实际替换PayPal的LaravelCurrency调用与Stripe重复的银行换算实现，移除amrshawky/laravel-currency 4.0.0和amrshawky/currency 1.0.0。框架仍为Laravel6，PayPal和Stripe仍使用原支付SDK。

## 报价来源与协议

- PayPal使用[Frankfurter v2 CNY/USD](https://frankfurter.dev/currencies/cny/usd/)的公开参考汇率。固定HTTPS GET https://api.frankfurter.dev/v2/rate/CNY/USD，核对base=CNY、quote=USD、正十进制rate与真实日期。旧exchangerate.host调用没有access_key，失败返回null；本批采用公开v2接口，不保留失效核心包装。
- Stripe保留原招行 https://m.cmbchina.com/api/rate/fx-rate 的报价来源，要求body.data中唯一美元行的正rtcOfr及ratDat。原代码按每100美元报价计算100/rtcOfr，本批保留这一单位与字段，不更换银行来源。
- 所有请求TLS校验、禁止重定向、连接3秒/总计5秒。HTTP错误、超时、坏JSON、币种不符、缺价、重复美元、未来日期和超过7个日历日的报价均中止换算。Frankfurter按UTC日期、招行按UTC+8日期核对。招行本批验证日期，不以ratTim提供分钟级新鲜度保证。

两类报价是不同的参考价格；本批不把它们视为相同汇率，也不代表卡组织或银行实际结算价。

## 金额精度与兼容

CurrencyRateClient在JSON解析前保留数值token的十进制原文，不经过PHP浮点金额运算。CurrencyAmount要求订单金额为正值、最多8位整数/2位小数，对应现有DECIMAL(10,2)字段；报价最多8位整数/12位小数。使用BCMath直接计算金额乘以完整报价比例，到最终美元分位才执行一次四舍五入。移除依赖后根清单直接声明Guzzle和ext-bcmath。

| 输入 | 旧银行算法 | 本批算法 |
| --- | --- | --- |
| 100.00 CNY，rtcOfr=671.050 | 汇率先截为0.14，14.00 USD | 按100/671.050计算，14.90 USD |
| 28.88 CNY，rtcOfr=671.050 | 4.04 USD | 4.30 USD |
| 100.00 CNY，Frankfurter rate=0.14928 | 原API外部舍入 | 本地得到14.93 USD |

PayPal Item.price、Details.subtotal及Amount.total使用同一固定两位十进制字符串。Stripe转换成无小数的美分字符串；订单完结仍使用原人民币订单金额。正人民币订单换算后若不足0.01 USD，或超出当前金额范围，返回明确支付提示，停止创建收费请求。免费订单仍走原零金额通道。

收银页的返回地址改由Laravel URL生成器构造，避免依赖缺失的全局SERVER_PORT和路径斜线拼接。

## 缓存与失败行为

配置CURRENCY_QUOTE_CACHE_SECONDS默认300秒，范围0–3600；0禁用。按来源和币种缓存成功报价，不缓存订单金额或错误。缓存读取时再次检查抓取时间、当前TTL、报价日期和十进制格式；降低TTL会立即影响已有报价。缓存故障直接获取有界的实时报价；供应商失败返回公开提示，没有固定0.13或过期报价回退，也不包含原响应或异常链。

默认兼容模式不持久化每笔订单的美元金额，展示与收费跨过TTL时可能重新定价。现已提供[可选持久订单报价与收款核对](payment-quotes.md)，默认关闭、安装新增迁移后可启用，按订单/渠道冻结并验证实际收款。供应商缓存的冷启动仍无跨进程请求合并，不宣称并发只请求一次。

## 验证与迁移边界

PHP7.4全量160 tests/1042 assertions通过。新增53个用例覆盖精度、金额边界、两个HTTP协议、日期、超时/脱敏、按来源缓存、TTL关闭/缩短/过期、缓存故障、成功恢复，以及实际PayPal/Stripe路由的SDK字段和收费失败条件。PHP8.2独立模块36 tests/226 assertions通过；没有在PHP8.2启动当前Laravel6应用。

支付SDK使用隔离替身；没有真实收费、店铺数据库或外部通知。仅对两家公开汇率端点进行了只读字段核验，测试请求全部使用MockHandler。PHP7.4平台检查、Composer清单/锁一致性通过；实际依赖移除0安装/0更新/2移除，根项目仍有11包30条审计公告。

禁止脚本安装后要刷新应用生成的包发现清单，避免已删除Provider残留；本地清单已通过独立PackageManifest构建，无应用启动或环境文件读取。测试配置将config/routes/packages/services/events缓存全部隔离到tests/.runtime，启动前从当前installed.json重建包发现。框架迁移不因此视为完成，详见[候选依赖图](framework-migration.md)。
