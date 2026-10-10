# 易支付、V 免签与 Paysapi 原表单协议验收

本批保留现有商户字段、七种结账方式和回调路径，将三种旧表单协议从控制器内的拼接逻辑移到独立客户端，并接入实际订单履约事务。根项目框架和依赖版本没有因此切换。

## 已复现并修复的行为

[原生路由回归](../tests/Feature/FormGatewayRouteTest.php) 在修改前验证了三类失败：易支付收到已签名的 WAIT_BUYER_PAY 仍返回 success 并发货；V 免签收到已签名的支付宝 type=2，而订单选择微信 vwx，仍返回 success；Paysapi 空交易号经过输入中间件转为 null，回调成为 HTTP500。这三类输入现在返回 fail，订单、卡密和销量保持未履约。

所有通知均使用当前请求的原始数据。易支付和 V 免签按既有 GET 路由读取 server QUERY_STRING，保留空格、加号、换行和零值的签名字节；没有使用会规范化查询串的 getQueryString()。Paysapi 按既有 POST 路由读取原始 form body，不混入 query。共同的 [FormNotification](../app/Payments/FormNotification.php) 限制 64KiB，拒收重复键、数组键、错误百分号编码和非字段名。Paysapi 仅接受 application/x-www-form-urlencoded；GET 路由不扩展为新协议。

完成验签后，通过 [PaymentGatewayLock](../app/Service/PaymentGatewayLock.php) 的 receiptGuard 在 [实际发货事务](../app/Service/OrderProcessService.php) 内重查订单、价格、渠道、支付方式、处理路由和三处商户字段，并保留现有现代付款绑定互斥。回调读取 Pay::find，渠道关闭后仍接收已付回执；过期付款进入人工处理，保留交易号而不消费已释放库存。同笔通知只履约一次，其他交易号返回 fail。

独立审查还复现了公共履约服务将交易号 string0 当空值：已完成订单 stored=0/incoming=DIFFERENT 和 stored=TXN2027/incoming=0 在三个渠道均错误返回 success。主代理追加九个实际签名路由用例，六个差异回执在修复前失败；将 bool 判断改为显式比较空字符串后，六种差异回执均返回 fail，三个同笔零交易号对照仍成功且卡密/销量/通知各一次。原有可选空交易号调用语义保留，不把零值重新归为空。

## 保留的签名与金额语义

| 协议 | 原配置与结账方式 | 结账签名 | 通知签名与业务检查 |
| --- | --- | --- | --- |
| [YipayClient](../app/Payments/YipayClient.php) | merchant_id 为 pid、merchant_key 为提交地址、merchant_pem 为密钥；alipay/wxpay/qqpay | 非空原始字段按 ASCII 排序，排除 sign/sign_type，拼 raw key=value，再追加密钥取 MD5 | 同规则包含所有扩展字段；必须 TRADE_SUCCESS，验证 pid/type 回显（存在时）、MD5 类型和正金额；控制器匹配选中方式与有效交易号 |
| [VpayClient](../app/Payments/VpayClient.php) | merchant_id 为通讯密钥、merchant_pem 为自托管根地址；vzfb=2、vwx=1 | MD5(payId+param+type+price+key) | MD5(payId+param+type+price+reallyPrice+key)；类型须匹配所选方式，记录 payId，price/reallyPrice 都为正人民币金额 |
| [PaysapiClient](../app/Payments/PaysapiClient.php) | merchant_id 为 uid、merchant_pem 为密钥；pszfb=1、pswx=2；原固定支付地址 | MD5(goodsname+istype+notify_url+orderid+orderuid+price+return_url+token+uid) | MD5(orderid+orderuid+paysapi_id+price+realprice+token)；必须有有效 paysapi_id 和正人民币金额；记录 paysapi_id |

使用 hash_equals 校验 32 位 MD5 字符串，通用异常不保留密钥、原始响应或异常链。金额以十进制字符串签名，不经浮点数重拼；通知允许人民币零、一、两位小数，拒收指数、负数、零和超两位精度。Yipay 空字符串排除，但回调 string0 仍参与签名。没有引入新的 RSA、HMAC、事件字段、时间窗口或商户凭据。

V 免签及 Paysapi 的签名区分商品原价和渠道实际收款金额。沿用原已签名 price 并精确匹配订单，不用 reallyPrice/realprice 替换商品价，也没有加入强制两者相等或实付必须高于原价的规则。实际交易用例验证 7.01 元订单的已签名实付 7.00/7.02 都沿用 7.01 履约，而商品价 7.00、7.019 或指数表示不履约。V 免签文档解释了收款区分；Paysapi 未查到可用的当前官方完整文档，此处仅保留上游原控制器区分 price/realprice 的语义。未纳入原固定签名的额外字段不被当作新增付款成功条件。

V 免签保留原日期加随机数的 payId 格式；新客户端显式接收 payment_id，以便校验签名与复现。自托管 HTTP/HTTPS 及路径前缀保留，结账地址拒收 userinfo、控制字符、fragment（含空 #）和畸形编码。Vpay 根地址禁止已有 query，以免把 createOrder 拼入查询参数；缺少末尾斜线时补齐路径。

## 浏览器表单与返回页

[共同提交视图](../resources/views/common/payment-submit.blade.php) 由 Blade 转义 action、字段名和值，保留 Yipay GET/Paysapi POST 自动提交，并提供立即支付按钮。字段中仅有签名，不输出商户密钥。实际 HTTP 渲染及 DOM 解析验证七种模式，Paysapi 中包含引号和 script 文本的 orderuid 保持字段原值且未成为第二个脚本；DOM 提取后的字段仍符合独立签名 oracle。表单响应标记 no-store/private。

三个同步返回页立即跳转订单详情，不再固定 sleep 1/2 秒。返回页没有发货副作用；订单支付状态仍由回调和已有详情轮询确认。这里只验证实际生成 HTML、DOM 和重定向，没有声称打开了真实商户收银台。

## 本轮验证

- 原生三个网关交易路由：91 项 / 797 条断言，PHP7.4/Laravel6 与 PHP8.2/Laravel12 均通过，真实订单/卡密/销量事务，仅替换通知发送。
- [易支付独立测试](../tests/Unit/Payments/YipayClientTest.php)：100 / 1066，两个 PHP 版本通过，包含独立原字节 oracle、旧控制器固定黄金值、成功/失败业务状态、元数据和 URL 边界。
- [固定签名独立测试](../tests/Unit/Payments/FixedFormClientsTest.php)：47 / 305，两个 PHP 版本通过，覆盖两协议全部模式、逐字段篡改、金额微调、额外未签名字段、输入形状和地址边界。
- 审查修复后全量旧树 1498 / 13051、候选全量 Unit/Feature 加六项 SMTP 1504 / 12595，均无排除通过；相对上一批新增 238 项（100+47+91）。[旧树报告](../tests/.runtime/form-gateways-root-final-27.xml)、[候选报告](../tests/.runtime/laravel12/form-gateways-full-final-27.xml)。
- 系统 PHP8.2 独立支付目录 904 / 7478，通过并保留旧 Pay2 的 Collection/Config 弃用提示；本轮十一处 PHP7.4 语法检查通过。

重放命令（PowerShell）：

    & tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini vendor/bin/phpunit --do-not-cache-result --log-junit tests/.runtime/form-gateways-root-final-27.xml
    php -d extension=gd tests/Compatibility/Laravel12/vendor/bin/phpunit -c tests/Compatibility/Laravel12/phpunit-core.xml --do-not-cache-result --log-junit tests/.runtime/laravel12/form-gateways-full-final-27.xml
    php vendor/bin/phpunit --do-not-cache-result tests/Unit/Payments

协议参考：[易支付 MD5 提交字段](https://yi-zhifu.com/doc)、[易支付交易成功通知约定](https://www.ezfp.cn/doc/pay_notify.html)、[V 免签原始 API 文档镜像](https://git.dov.moe/Mirror/vmqphp/raw/commit/25bee53c6183df32f5cdd1eeeb2687e9468aad9d/public/api.html)、[Paysapi 上游原控制器](https://github.com/assimon/dujiaoka/blob/master/app/Http/Controllers/Pay/PaysapiController.php)。这些资料辅助核验旧字段语义，不代表已验证各当前供应商协议或实际收款服务。

没有读取商户凭据、发起真实付款、修改依赖清单/锁、部署或提交代码。没有在本轮加入新的数据库迁移。Vpay 的旧随机交易号生成仍没有持久唯一绑定；共同回执锁随后已在[MySQL双框架验收](mysql-concurrency.md)中通过15组竞争，整个夹具各36组/85 worker及七份迁移通过；真实部署表结构、merchant/liveTLS、后台完整页面与最终容器/worker/scheduler 尚待验收。该表单阶段保留了 Epusdt、TokenPay 和既有 Bepusdt 修改；后续[旧数字货币阶段](crypto-gateways.md)完成两种原 JSON 协议，再由[Bepusdt阶段](bepusdt-compatibility.md)补齐真实回执，最新根 2100/16764、候选 2106/16306。整体目标继续进行。
