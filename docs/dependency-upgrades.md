# 分批依赖升级

## 第一批：Carbon

锁文件将nesbot/carbon从2.57.0升级至稳定版本2.73.0，增加carbonphp/carbon-doctrine-types 2.1.1和psr/clock 1.0.0。应用依赖约束保持原样。

使用本地PHP7.4.33比较运行时执行Composer2.10.1：

```text
composer update nesbot/carbon:2.73.0 --with-dependencies --minimal-changes --no-scripts --no-plugins --no-interaction
```

首次下载遇到TLS错误，锁文件已写入；随后按锁文件install成功。没有忽略平台要求，也没有执行应用安装脚本。完整PHP回归42 tests / 242 assertions通过，覆盖订单期限和缓存到期；check-platform-reqs在此PHP7.4运行时全部通过，composer validate通过，仅保留原二维码依赖精确版本提示。

## 第二批：PHPUnit

PHPUnit9.5.4升级至稳定版9.6.38，相关工具链共19项更新、5项移除（包括Prophecy及其文档反射依赖链）。使用 `composer update phpunit/phpunit:9.6.* --with-dependencies --minimal-changes --no-scripts --no-plugins --no-interaction`，未忽略平台要求。新版工具完整42 tests / 242 assertions通过；PHP7.4平台检查、清单及锁文件校验、补丁格式检查通过。安装结束报告14个包44条漏洞公告，仍需后续处理。

Laravel6.20.45最小更新预演被Composer漏洞公告检查阻止，未修改框架包，也未关闭该检查。锁定的Dcat2.0.24-beta只声明Laravel5–8兼容，需联合评估后台、框架与支付组件升级。

## 第三批：HTTP客户端与验证码图片库

Guzzle7.4.5→7.15.5、PSR7 1.9.1→2.13.1、Promises1.5.1→2.5.3、Intervention Image2.5.1→2.7.2、intl-idn polyfill1.27→1.43，新增psr/http-factory1.1.0。旧图片库约束PSR7 ~1.1，因此必须联合更新。使用稳定版约束与minimal-changes预演后安装，无忽略平台要求。

43 tests / 247 assertions通过，含真实验证码生成和解码、回调HTTP及支付用例；PHP7.4平台检查、Composer校验、diff检查通过。安装报告11个包30条漏洞公告，仍未完成框架及目标PHP迁移。验证码用例不涉及外部极验服务。

## 第四批：解除旧极验框架耦合

实际移除germey/geetest 3.1.0，其约束为PHP ^7.3及Illuminate不超过7。新增应用内V3协议客户端/会话服务/门面/Provider，保留现有端点、下单规则和模板接口；HTTPS固定端点、TLS校验、有限超时、无重定向、严格应答匹配。会话绑定已发challenge前缀/配置指纹，5分钟有效，顺序重放失效；原离线协议降级保留，不代表在线验证能力或并行请求的原子消费。详见[适配层验证](geetest-adapter.md)。

使用 `composer remove germey/geetest --minimal-changes --no-scripts --no-plugins --no-interaction`，0安装/0更新/1移除。新增19个用例；PHP7.4完整107 tests/706 assertions通过，独立客户端在PHP8.2下7 tests/36 assertions通过。PHP7.4 check-platform-reqs、Composer validate及自动加载通过；MySQL独立脚本从classmap扫描排除，仍通过显式bootstrap调用。根项目审计仍为11包30条公告。

[现代框架候选图](framework-migration.md)经真实PHP8.2+单次gd扩展完成隔离dry-run解析，共134包；候选需更换后台、支付SDK、汇率包装、代理和邮件实现。未安装候选依赖、未更新实际框架、未忽略平台要求或公告阻断。解析成功仅证明依赖组合可解，应用调用方及全量新框架启动仍待迁移。

## 第五批：汇率包装与金额精度

实际移除amrshawky/laravel-currency 4.0.0和amrshawky/currency 1.0.0，0安装/0更新/2移除；显式声明Guzzle及ext-bcmath。应用内报价客户端支持PayPal的Frankfurter v2与Stripe原招行报价来源，十进制比例保留到最终美分舍入，不再缺价回退0.13。按来源缓存成功报价、默认300秒/0禁用/上限3600，供应商失败停止收费。日期超过7天、币种/金额/响应异常均有回归；详情见[金额与报价边界](currency-conversion.md)。

PHP7.4完整160 tests/1042 assertions通过，PHP8.2独立模块36 tests/226 assertions通过，平台检查与Composer清单/锁校验通过。实际根框架和支付SDK未升级，根审计仍11包30条公告。禁止安装脚本后刷新本地生成的PackageManifest；测试包/服务缓存现隔离到tests/.runtime，修正Laravel6在Windows误拼接绝对缓存路径的问题。现代候选清单删除失效Currency核心并声明实际换算依赖，新dry-run通过133包，仍仅为依赖预演。

## 第六批：Stripe SDK与订单唯一付款对象

Stripe7.84.0实际升级到22.0.0，最低PHP从7.2.5提高至7.4，与发行包的真实要求一致。minimal-changes dry-run后实际update只更新Stripe一包，未执行安装脚本。默认旧路径保留账户默认API版本；启用STRIPE_PAYMENT_INTENTS时使用独立现代客户端/Payment Element、持久订单唯一绑定及服务端实收核对，详见[付款意图迁移](stripe-payment-intents.md)。这不是仅提高SDK约束：创建断连同键恢复、跨币种/渠道停止、旧收费入口409、CSRF和实际Charge/CNY完结均已接入。

PHP7.4完整300 tests/2549 assertions，PHP8.2独立支付模块50/260通过；MySQL10组竞争/33worker及四份前向迁移、实际Blade桌面/移动交互通过。平台检查与清单/锁校验通过，仍有既存二维码精确版本提示和11包30公告。实际现代框架仍待迁移；现代候选清单已同步Stripe22并重新dry-run通过，没有安装候选依赖。

签名Webhook与后台对账现已追加可选实现，实际发货事务内重复核对绑定/渠道，关闭浏览器后的恢复由持久收件与调度器触发。该批PHP7.4 327 tests/3003 assertions、独立PHP8.2 58/542及MySQL12组/37worker通过。本轮未更改依赖锁文件；详情见[异步确认与人工恢复](stripe-webhooks.md)。

## 第七批：PayPal Orders V2协议与唯一捕获

新增可选PAYPAL_ORDERS_V2，持久Order/create/capture键、冻结报价与账户指纹，原生审批返回仅查询绑定ID；捕获后重新GET Order及独立GET Capture核对真实单次final实收，在原CNY发货事务内再次锁核。默认保留v1，现代模式关闭旧执行及未验签通知入口，见[启用与恢复边界](paypal-orders.md)。

实际Composer show2.4.0和minimal-changes dry-run证明：官方SDK2.4虽然支持PHP7.4，apimatic/core~0.3.17要求SymfonyHttpFoundation>=5.4，与Laravel6自身要求^4.3.4冲突。撤回本轮临时root声明，未安装或更改根锁；已有Guzzle7直接适配官方V2协议并完成真实编码测试。旧REST SDK留给默认旧路径，SDK替换/移除仍需框架迁移与旧对象清账，Laravel12候选SDK未安装。

PHP7.4完整367 tests/3652 assertions、独立PHP8.2支付64/616、MySQL16组竞争45worker及六份迁移通过。新跨供应商独立订单交错实测复现间隙锁死锁，修复共有检查仅锁存在主键后同场景重放通过；一对象捕获POST替身和实际卡密/销量均一次。Composer validate保持清单/锁一致，既存精确二维码版本提示仍在。没有实际收费、生产设置变化或依赖审计清零声明。

## 第八批：PayPal签名收件与后台对账

新增默认false的PAYPAL_RECONCILIATION及000007InnoDB收件/状态迁移。真实Guzzle把原事件字节送固定官方verify-webhook-signature API，SUCCESS后在同Pay锁内重核账号/mode/endpointID并持久最小字段才200，FAILURE400/API或存储故障503。后台无需浏览器、复用已存绑定/捕获键，GET核实或APPROVED后同键捕获，最终实收在原交易guard内发货；未知25h创建可由已验签事件候选+服务端reference恢复，不重放创建。见[签名事件与人工恢复](paypal-webhooks.md)。

真实回归额外闭合两问题：旧暂停25事件按ID反复占批次导致后来恢复事件饥饿，改按available_at再ID；合法OrderCOMPLETED/CapturePENDING原被当失败，现保持未发货继续等待、不占失败预算，实收完成后再独立核对。PHP7.4最终399tests/4049assertions、PHP8.2独立70/687通过；MySQL18组49进程/15表/7迁移真实通过，供应商API为本地替身。原SDK冲突/根锁与框架未因本轮变化，仍待隔离目标框架实际启动、邮件/代理/异常处理及后台兼容迁移；真实provider/生产worker和退款仍需后续验收。

## 第九批：实际隔离框架启动与兼容边界

候选不再只做解析：独立安装133包并保存锁，在PHP8.2.31实际启动Laravel12.69.3/DcatX1.2.2应用158路由。异常处理签名改Throwable并兼容旧Exception-only委派，AppException返回HttpResponse；默认缺失layui视图已复现并对齐unicorn。代理/维护入口按可用父类兼容，显式四字段转发掩码保持旧行为；原尺寸验证码profile120x36固定。环境/缓存/存储都在测试目录；维护文件创建前断言路径，根店铺环境未加载。

根PHP7.4全量406/4071通过，目标明确核心子集350/3136通过；完整候选探索405项仍3errors/24failures，27项集中旧PayPalv1及Swift邮件。候选SDK存在不代表调用方已迁移；后续先完成Symfony transport/worker刷新/发送接受确认及outbox回归，再处理旧对象兼容和目标数据库/后台/正式平台。见[隔离复现与完整失败边界](../tests/Compatibility/Laravel12/README.md)。目标validate/platform-reqs通过，未关闭平台/公告校验，根依赖继续旧树。

## 第十批：Symfony邮件运行兼容

邮件调用已按实际API兼容旧Swift与现代Symfony。长驻worker刷新最新主机、用户名、发件地址和named mailer缓存；明确保留ssl/tls/空配置在自定义端口与465的语义。真实SMTP传输+无网络脚本流复现EHLO失败后HELO回退绕过require_tls，应用层握手检查已修复。outbox取消、空结果/空收件信封、真实传输异常均保留密文并退避重试，接受后清除快照；不把SMTP接受当作最终收件箱投递。

最终根PHP7.4全量414 tests/4110 assertions通过；候选明确核心382/3316通过，含23份Feature与6项SMTP协议测试。目标全量414项仍13 failures/0 errors，集中两个旧PayPal v1类，邮件原14项失败已闭合。根依赖未变，先保留旧对象兼容，再接Yansongda/后台/目标数据库/正式平台验收。详细契约和可复现证据见[邮件运行兼容](mail-runtime.md)。

## 第十一批：移除废弃PayPal SDK并闭合目标全量

旧网关与回调已从ApiContext/Payment对象改为数组和原生v1协议，继承已有OAuth/固定HTTPS/限时/无重定向/脱敏传输。保留旧链接与live账户语义，取消直接返回，GETapproved不再execute；关闭冻结时也验证单一invoice、USD、completed Sale和parent_payment，保持原非冻结Payment ID交易标识。未知执行在订单行trade_no持久预留，前后事务核查价格/渠道/凭据/报价；即使GET仍created也只查询，重进网关禁止另建对象。仅HTTP200应答旧未验签通知，不记录正文或发货。详见[兼容与人工恢复](paypal-v1-compatibility.md)。

实际Composer dry-run及remove均0安装/0更新/1移除paypal/rest-api-sdk-php1.14.0，脚本/插件关闭；根清单、锁与vendor同步，其他版本未动。根优化autoload排除隔离候选vendor及.runtime，原MySQL独立类排除保留。validate及PHP7.4平台检查通过；根审计仍11包30公告，Laravel6/Dcat/Swift/Yansongda后续仍需迁移。

最终原框架557tests/5060assertions、隔离Laravel12全部Unit/Feature及SMTP563/4622通过，不再排除两个混合旧付款类；HTTP200兼容调整后只重验受影响案例，两框架各1/8通过，其余证据复用。旧协议客户端113/629双PHP通过，真实Guzzle路由与实际卡密/销量事务30/321双框架通过；PHP8.2独立支付目录183/1316通过。首次审查指出unknown后GET仍created会重复execute，精确失败3次对比持久预留修复后1次，含三天后查询及后续approved恢复。当前仍非真实provider/后台/目标MySQL/容器验收，正式根框架未切换。

## 第十二批：支付宝SDK3与微信原凭据协议

已安装Pay3.7.20默认支付宝流程强制证书序列号，现有普通RSA商户配置需应用StartPlugin保留原公钥模式；官方业务/签名/响应验签插件保留。支付宝客户端跨SDK2/3刷新单例配置、适配wap→h5及PSR7/HTTP响应，精确金额字符串与真签名回执/订单/官方二维码验证。微信SDK3仅V3，而现有API-V2密钥无V3证书，因此独立V2客户端保留Native统一下单/XML/MD5-HMAC签名，不擅自改变协议或配置。详见[兼容契约](cny-gateways.md)。

两处回调均显式使用当前请求验签，校验成功状态/账号/CNY/精确金额/交易标识；微信返回协议XML确认而非裸文字。签名通过后在实际发货事务中重查选中网关/订单价/三处商户字段/处理路由，并保留启用的现代绑定互斥；重复发货、关闭已付款渠道及过期手工处理都由实际交易测试覆盖。网关错误不显示SDK原文，TLS/有限超时/固定官方host/64KiB/no-replay客户端边界独立验证。

独立审查复现request bag被TrimStrings清洗后真实RSA通知失败，parent原生路由先复现fail，改为64KiB原始表单解码并保留签名字节；拒收重复/数组键/畸形编码和错误Content-Type，两个框架原始空格回归通过。新增228项，最终原框架全量785/7188、候选全量791/6734通过。支付宝17/1106(root)、17/1092(candidate)，微信160/604(PHP7.4)、160/602(PHP8.2)，原生路由51/418双框架。PHP8.2根独立目录360/3024通过但Pay2vendor有既存Collection/Config弃用提示；未把它写成新框架全兼容。十处PHP7.4语法通过，原始正文修复后受影响PHP重新lint。本批依赖/锁/真实数据库未改，根Pay2公告仍待根PHP/框架实际切换；SQLite和mock不替代新锁MySQL竞争、真实merchant/后台/worker/container验收。

## 第十三批：Payjs独立原协议与生产XML扩展

Payjs原包装在构造单例时固化merchant配置，而controller后改config不刷新；notify依赖清洗后的全局request并未核对业务成功。新PayjsClient保留固定Nativeform/原MD5签名全部字节规则，包括array_filter零值过滤和key的urldecode；实际已安装SDK sign/checkSign生成固定golden向量后移除框架包装，独立oracle验证两PHP契约。响应验签及精确invoice/整数fen/NativeQR核对，mchid有回显则绑定；原始form共享已验证的FormNotification解析，不受TrimStrings/参数污染，业务失败、小数fen、非CNY及空交易号停止发货。相同锁内receiptGuard用于三网关，关闭已付与过期人工流程保留。详见[Payjs兼容契约](payjs-compatibility.md)。

根/候选实际remove xhat/payjs-laravel1.6.0各0install/0update/1removal，其他版本未变。根112包，候选132包，PHP/Framework/Dcat/Yansongda版本保留。新XML实现直接使用DOM，两个manifest显式require ext-dom，lock刷新零包变动；当前validate/check-platform-reqs与候选158routeprobe通过，原exactQRCode提示保留。根Composer仍11包30公告，新候选当前报告无公告；移除包装不等于完成根升级或消除根告警。

Payjs独立213/936双PHP通过，共享原生三网关71/575双框架；新增233项（client213+route20），最终根1018/8281与候选1024/7827全量通过，无Unit/Feature排除。系统PHP8.2独立支付573/3960通过，根Pay2既存vendor弃用仍有。独立只读审查无新增可复现问题，局部51/298及原始form内存检查通过；原生路由和全部发货事务由主代理运行，审查未冒充MySQL并发或真实provider。搜索服务失败/旧官方doc404后仅查可访问SDK原协议，未请求付款API；当前真实merchant/liveTLS/后台/目标MySQL/最终worker-container仍待验收。

## 第十四批：Mapay/Coinbase旧协议与回执锁闭合

原生测试先复现Mapay已验签pay_no却存pay_id导致另一交易假重复，改为真实pay_no后同笔只发一次、另一笔fail；Coinbase旧globalRequest在隔离入口500，独立原循环证明尾项pending错误控制发货，改当前Request原始HMAC和足额confirmed/resolved CNY单笔候选。保留不累计partial及原本金发货金额/chargecode，原fee精确BCMath不擅自向分截断。回执关闭渠道已付/过期人工/现代绑定与订单金额、渠道、方法、route、三merchant字段变化在共同锁内核对；新pay_check守卫添加三CNY原生上下文用例。通用fixture提取实际schema/processor，通知之外交易逻辑不替身。详见[旧协议交易验收](legacy-gateway-callbacks.md)。

Mapay保留全部三模式及原7.4默认act/pay_type整数0省略，同时string0参与回调签名，actualhelpergolden+独立oracle双PHP验证。失败代理已有完整文件，经接管发现7.4parse_url空fragment差异，明确拒绝#后闭合。Coinbase新Guzzle固定host/version/认证、TLS和bounded关闭stream/no redirect/no POSTreplay、通用错误；公开201创建/官方CLI payUUID样例补入正例后修复过窄成功限制。独立审查真实HMAC+controller内存复现超过12位小数fee回执失败，三原生正例先fail后用128byte限定精确十进制比较修复，并验证无限趋近但低于本金仍fail。

独立Mapay63/1107root与63/1105candidate（root额外两actualhelperassert），Coinbase121/1042双PHP。Legacy原生55/432双框架，共享CNY74/596；新增242项，完整根1260/10883、候选1266/10427无排除通过；系统PHP8.2独立Payments757/6107通过保留旧Pay2弃用提示。新候选两断言遇HTMLescape/SQLite返回类型差异已按原始ack/规范十进制修复，未弱化金融检查。十PHP7.4 lint/gitdiff--check通过，只有既存CRLF。没有修改manifest/lock/package环境，112/132包的既有平台验收可复用；未切根框架或声称真实付款/并发数据库/新版Coinbase模型完整覆盖。剩余其他网关、后台、目标MySQL、正式PHP/框架/container-worker仍待验收。

## 第十五批：易支付、V免签与Paysapi表单协议

保留七种旧结账方式/商户字段及GET/POST回调。真实已签名回归复现易支付WAIT_BUYER_PAY仍发货、Vpay方式不匹配仍发货、Paysapi空交易号变null导致HTTP500；移到显式原协议客户端并核对业务字段后不履约。当前Request原query/form字节经有界解析，拒收参数污染而不被TrimStrings改签名；所有有效回执接共同锁内receiptGuard，关闭已付与过期人工恢复保留。普通form由Blade转义，Paysapi带引号/script文本仍是一个字段，DOM提取后验签正确；七种原结账方式通过。同步返回不再固定等待，仍只跳转不发货。渠道实收微调沿用原已签名商品价，不强加实收等于商品价；未将Vpay/Paysapi未签名扩展字段当业务信任。详见[原协议与验收](form-gateways.md)。

独立审查发现公共completedOrder将字符串交易号0当空值，三渠道两方向的不同回执均误返回success而数据库未重发货。九个原生签名用例先复现六次错误确认，再以显式空字符串比较修复；同笔0通知仍只发货一次。审查修复后两个全量重跑通过：根1498/13051、候选1504/12595，无排除；新增238项，包括Yipay100/1066、固定协议47/305、原生三网关91/797双PHP/双框架验证。系统PHP8.2独立支付904/7478通过，十一处PHP7.4语法通过；既存Pay2弃用提示保留。没有改依赖/锁/系统配置/迁移，112/132包既有平台验证复用；剩余Epusdt/TokenPay、既有Bepusdt变更审查、后台/目标MySQL并发和最终运行环境。

## 第十六批：旧Epusdt/TokenPay JSON协议

冻结旧Epusdt/TokenPay提交区分当前新版文档，不改变MD5/API路径、商户字段和十二种入口。真实已签名原生回归先复现四种未付/过期仍ok并履约，独立客户端及当前原JSON解析后按付款状态收款。TokenPay必须CNY/配置币种，ActualAmount为法币而非Amount/PayAmount；空邮箱以订单号提供旧服务端必需用户标识。共同锁内receiptGuard继续核查订单/渠道/三商户/方式及现代绑定，关闭已付和过期人工恢复通过。金额字符串直接编码原JSON number，serialize_precision=17不改分值，不自动重试建单，异常/response脱敏。详见[冻结协议、重放和边界](crypto-gateways.md)。

审查发现约8KiB合法长字段触发PCRE JIT栈；五项解析正例先全失败，再改分块占有式表达式，原有规则、完整64KiB及转义大字段通过，六项原生真实签名也履约。最后全量根1829/15308、候选1835/14850，无排除含六SMTP；新增331=101Epusdt+119TokenPay+32JSON+79native，原生79/804双框架，系统PHP8.2独立支付1156/8929通过保留原Pay2弃用，九PHP7.4语法通过。72探索/73实现/74审查均完成且结果收集，唯一新增审查问题已实际修复。

依赖/锁/系统配置/迁移不变，既有112/132包平台证据复用。没有访问真实付款、商户凭据、SMTP、目标数据库或部署/提交。既有Bepusdt保留且只读确认detail过滤关闭网关/完成调用缺receiptGuard，后续真实回执验收；后台/目标MySQL新竞争/现代测试工具链/正式PHP框架与container-worker-scheduler仍待完成。

## 第十七批：Bepusdt 原协议与实际回执事务

在现有控制器和八项测试上继续实现，未以旧 mock 委派替代实际订单/卡密/销量。三项真实回归先复现关闭渠道漏掉已付款、已签名 USD 仍发货、验签后方法变化仍发货，再以独立 BepusdtClient、当前原 JSON、Pay::find 及共同 receiptGuard 修复。冻结 v1.20.4 实际回调模型无 fiat/trade_type、amount 为 CNY，缺回显保留旧兼容而有回显必须匹配；只 status2 履约、1/3 生命周期确认。固定付款发送分支只接受精确 ok，原 success 改正。基础/子路径/完整 endpoint 和原返回 query 保留，serialize_precision17 数字金额不误差，HTTP 在事务外、流关闭/限时/脱敏/no automatic replay。详见[Bepusdt 验收](bepusdt-compatibility.md)。

新增271=204client+67native。最终根全量2100/16764、候选全部Unit/Feature加6SMTP2106/16306，无排除通过；新增路由67/641双框架、client204/815双PHP，原八项保留并适配 Pay 实读/可调用 guard/ok，根8/93 vs候选8/53。系统PHP8.2独立支付1360/9744通过、旧Pay2弃用保留，五PHP7.4 lint通过。独立审查无新增可复现P1/P2，其纯内存核对不冒充MySQL并发。探索任务失败后的版本提示由主代理读取固定原生源码和注解tag/commit独立补证；实现与审查已收集。

本批无包/锁/配置/迁移变动，根112/候选132平台证据复用。既有用户文件已读后适配，没有回退未知改动；未读商户凭据/生产DB/真实付款/SMTP或部署/提交。剩余后台/资产浏览器、目标MySQL全部新增迁移与receiptGuard/v1执行预留竞争、现代PHPUnit与最终PHP/framework/container-worker-scheduler，根框架保留。

## 第十八批：双框架 MySQL 回执与 v1 执行预留

在既有十八组竞争上新增共同receiptGuard十五组和真实旧v1三阶段六worker。根PHP7.4.33/Laravel6.20.42与候选PHP8.2.31/Laravel12.69.3各36组/85进程、十五张InnoDB夹具表、七份真实迁移up通过。--candidate与完整独立ini传给所有worker，沿用固定回环端口/库/账户/marker/datadir/协调锁；不读店铺dotenv或重置已有实例数据目录。实际app/framework/包与锁均不变，普通全量仍复用上一轮2100/16764及2106/16306。

八种回执上下文变更在真正数据库等待期间提交，两回执均停止履约；关闭已付恢复、过期库存保留、两现代绑定、同/不同/string0引用均验证。完整v1报价读取后第二道GET屏障强制争预留/履约；独立PDO证明POST前marker已提交，unknown唯一execute等待败者退出再503，stillcreated重试及checkout只查询无新对象，approved恢复一张实际卡/一次销量，总execute1/GET6、全部txn0。供应商与通知仅本地替身。

真实失败揭示原20ms观察会持续复用INNODB_TRX100ms缓存，按安装版本源码改150ms并在500ms后再次核对全部等待。独立只读审查发现不同引用Oracle允许空串/败者引用，修为最终trade_no必须等于唯一acceptedworker的请求值，再运行两个36组通过。全部72组的前后等待与170worker已核对，报告及重放见[MySQL验收](mysql-concurrency.md)。临时服务正常停止，未改系统/生产环境、付款、SMTP、部署或提交。真实部署表结构、后台/资产浏览器、现代PHPUnit和正式PHP/framework/container-worker-scheduler仍待验收，根框架保留。

## 第十九批：核心管理后台兼容与浏览器验收

实际HTTP内核、会话/CSRF、Dcat控制器/表单及匹配的已安装资源在两框架各通过43次页面检查、8个异步统计响应、56个本地资源URL；分类创建/更新、商品编辑器保存、文本卡密导入、系统设置文件缓存持久化、移动侧栏导航与退出均真实执行，没有伪造本地成功响应。仅在测试目录中生成合成SQLite数据和各自缓存/会话/上传存储，固定回环8198端口，组件主JS/CSS的HTTP字节SHA256与对应安装包相等。范围、截图及重放见[后台验收](admin-compatibility.md)。

七种业务日期在Laravel12原先全部序列化UTC ISO，统一保留旧本地Y-m-d H:i:s，Emailtpl复用BaseModel。文本导入真实复现候选Flysystem3先入库后delete(null)报错；仅非空文件路径清理同一public盘，兼容CRLF/LF/CR。日期7/28、导入11/156双树通过；原Dcat location已自动加后台前缀，显式admin_url不计已复现故障。移动首页入口不再拆字并移除QQ外部图片依赖。

共享模型变更后全量实际重跑：根2118/16948、候选2124/16490（所有Unit/Feature加六SMTP，无排除）；七份PHP7.4语法、浏览器语法、差异检查通过。独立只读审查无新增可复现P1/P2。无vendor/manifest/lock/systemPHP/migration变化，根112/候选132既有平台证据复用。核心浏览器验收不覆盖所有权限/批量/回收站/上传操作或真实服务；部署表结构、资源正式发布、现代PHPUnit、正式PHP/framework/container-worker-scheduler继续推进。

## 第二十批：候选PHPUnit11与共享旧运行器兼容

候选稳定约束^11.5实际安装11.5.57，Composer先预演后1新增/21更新/2移除，仅测试工具链；102运行包名称/版本/source reference逐项不变，开发30→29、合计132→131，根112及根9.6.38保留。validate/check-platform-reqs及实际应用158routes probe通过，保留原QRCode精确约束提示。详见[版本、证据与重放](phpunit-compatibility.md)。

29文件167provider注解保留并补单行全限定属性，133不同provider中新增66static（52纯数据、14helper依赖）。两个result helper撞PHPUnit11 final方法先真实fatal后改gatewayResponse；purehelper静态化保留oracle严格断言，Geetest provider改真实PSR7 Request不走网络。候选XML安装schema+failOnAllIssues/displayDetailsOnAllIssues/cachefalse/空suite失败，独立deprecation负对照验证子进程exit1，不用抑制或排除闭合。

最终根全量2118/16948、候选全部Unit/Feature+6SMTP strict2124/16493 exit0；三个额外断言来自新版Parameters在evaluate前显式计数，逐JUnit仅Coinbase/Epusdt/TokenPay三项各+1。旧/新发现XML规范化后2124项无缺失/新增/重复。29PHP7.4语法全过；根9在PHP8.2独立Payments仍1360/9744并保留旧Pay2弃用提示。独立只读审查无可复现P1/P2，关注当前复杂链/metadata/parser/schema，非逐行Git前后比对。PHPUnit11与PHP8.2是本地比较台阶，官方常规bugfix周期已结束，最终受维护测试平台随正式PHP选型继续推进。

## 第二十一批：受维护PHP8.5/PHPUnit13与表格依赖

官方Windows PHP8.5.11 NTS VS17 x64便携包36048897字节，SHA256实际校验后在独立完整INI运行，系统PHP保留。候选最低PHP约束^8.4、PHPUnit^13，实际13.4.1；OpenSpout4.28.5旧约束真实阻断8.5，按Dcat/easy-excel链最小更新至4.32.0。Composer先预演后2新增/21更新/2移除；102运行包完整锁元数据仅OpenSpout变化，另101不变，开发29/总131包保持。validate/platform/probe158routes通过，原QRCode精确提示保留。

真实8.5回归指出NAN转换警告、隐式nullable、旧PDO常量；应用金额转换前拒绝nonfinitefloat，两个异常/恢复构造及测试helper显式nullable，SalesCard无效可选默认修正，SSL常量按实际存在选择。13指出cacheResult及无expects mock用法弃用，改recordTestRunHistory及once/stub保留原语义，严格开关和负对照exit1保留。真实表格CSV/XLSX4项/36断言加入永久suite。

最终根PHP7.4/PHPUnit9完整2118/16948、候选PHP8.5/PHPUnit13 strict完整2128/16529通过；原2124用例逐项无缺失/重复，仅增加4表格case。229份当时app/config/tests编译无告警，11份本批文件PHP7.4语法通过；PHP8.5后台实际43页面/8统计/56资源及保存/CSRF/移动导航再次通过，临时服务已关闭。独立静态审查无可复现P1/P2。PHP8.5活跃维护至2027-12-31、PHPUnit13 bugfix至2028-02-04，Laravel12仍需正式框架选型；同轮PHP8.5 MySQL候选36组/85worker及七迁移真实通过，全部等待前后核对；实际部署schema和worker生命周期等继续推进，未部署/切换根。详见[维护周期、实际修复与重放](php-runtime-compatibility.md)。

## 第二十二批：维护期Laravel13与Dcat X2联合候选

候选稳定约束Laravel^13.0/Dcat X^2.0/Tinker^3.0，实际13.35.0/2.0.4/3.0.2；首次Composer预演真实指出Tinker2只接受到12，在2.x范围内解锁仍失败，按官方指南调整3.x后最小预演及实际安装通过。1新增/4版本更新/3移除：排序组件4.5.2→5.0.1、polyfill-php86新增、DBAL/deprecations/psr-cache移除；129包=100运行+29开发。完整锁metadata另94运行/29开发完全相同，phpdotenv5.7.0仅两个仓库URL组织迁移且version/reference不变，精确报告见[维护期框架验收](laravel13-compatibility.md)。平台/validate/probe158routes通过，无vendorpatch或忽略平台。

现有2128/16529在13实际strict通过、发现身份无缺失新增重复。新增永久候选边界12/45，真实App CSRF来源/token与pay例外、实际文件CacheManager/session新manager重读及类型保留；最终全部2140/16574 strict0/22.758秒。PHP8.5/Laravel13/Dcat X2真实后台43/8/56及认证保存/导入设置/移动导航通过，组件资源hash与安装包对应，截图实际核对。MySQL8.4.11/RR候选36组85worker七actualforwardmigration十五InnoDB表，36份锁报告前后全员/500ms/150ms逐项通过，服务器正常关闭且两个端口无监听。

官方Laravel13 bugfix至2027年第三季度、安全修复至2028-03-17，当前本地维护期候选组合已验证；根框架保留，旧根2118/16948复用第33轮未重跑。真实部署schema、扩展后台权限/批量/上传与worker/scheduler/container生命周期继续推进，未部署/发布/提交/推送。

## 第二十三批：真实队列生命周期与退避兼容闭环

依赖129包与框架版本保持第34轮。原ApiHook仅retryAfter在Laravel13新payload未生成backoff，真实database queue首次503失败后available_at无30秒延迟；同case旧根通过，新增backoff()返回同一既有值后候选恢复，保留修前失败artifact。共享原Worker原Kernel永久回归新增一项，当前全根2119/16963、候选2141/16588 strict通过，旧2140逐项无缺失重复仅新增一个ApiHookcase。见[真实队列进程证据](queue-lifecycle.md)。

PHP7.4.33/Laravel6与PHP8.5.11/Laravel13的独立SQLite持久queue夹具各五场景/24子进程/2452原始检查；A重复过期退券/future、B真实publisher与worker/重复token/503退避恢复/加密mail清空、C原Kernel双tick四Event与mutex原生持有释放、D真实queue:restart排空当前任务、E主动终止ownedworker保持reservation并补偿恢复全部通过。最终queue0、九outbox完成、expectedlost MaxAttemptsExceeded失败记录一条。两套报告和父独立逐项proof完整保留，探索8.2屏蔽已移除且正式root7.4guard/E_ALL。根原入口六哈希相同；未改生产cron/worker/容器配置或切根框架。Linux PCNTL/Supervisor/container托管、部署schema和扩展后台操作仍待验收。

## 第二十四批：显式资源权限与真实后台扩展验收

根112与候选129包图不变。真实browser修前确认reader仅GET路径在两个树均可Restore/Import越写，候选还可PUT下架；原根PUTHTTP200实际是无权限HTML且字段不变。应用ResourcePermission置web会话后/Dcat组前，七资源/三页存储methodpath、三Widget submit/upload/destroy映射POST，仍使用vendor签名验证。Restore/BatchRestore增加七模型白名单、eachID PUT、onlyTrashed/UI授权；batch同连接事务全部预查/行锁后restore，缺员不留部分状态。

独立只读审计指出installedHelper使用全局API路由名可错授权，父实际两树各2fail后权限期间绑定映射request并finally恢复，两案例永久保留。完整64/989权限回归通过，原2119/2141身份全保留；最终根2183/17952、候选2205/17577 strict0。正式root7.4和candidate8.5四角色浏览器各22页面/28拒写或原子性/13操作组/55本地资源通过；所有拒写SQLite业务/两盘hash完全相同。实际1806字节PNG自动upload+save+reload与51字节CRLFtxt显式upload+签名confirm+dedup/string0+源filedelete核对源/盘/HTTP三方hash；商品批删原卡密级联、商品批恢复后另卡密单恢复通过，未改变原业务行为。

本轮原核心43页面/8统计/56资源双树亦重新通过，含真实认证CSRF419/保存/缓存/移动logout；扩展独立8199不覆盖核心/历史固定report，最终截图实际查看。全部owned8198/8199服务已关闭。没有改vendor/public发布资源/根依赖/迁移/主机worker或cron，未部署/提交/推送；其余后台批操作/人工状态邮件/实际部署schema/Linux container与正式切根仍待验收。详见[请求状态证明、边界和重放](admin-extended-compatibility.md)。

## 第二十五批：人工状态邮件事务意图与原生补偿验收

新增默认false的MANUAL_STATUS_MAIL_OUTBOX，pending/completed/failure每次真实转换独立加密意图，SQL/updated监听器同连接事务；付款manager与pending客户通道独立，显式提交后event不重复。000008新增event_key并替换三列唯一键，旧通道空key保留唯一语义；回滚重放、嵌套独立模型同状态重复和同一对象重入丢意图均先真实失败后修复。非默认连接在保存前明确失败，防提交到没有消费者的数据库。三个原开关和旧schema保持各自契约，状态暂停包含旧排队任务领取。

新增46项状态用例，原2183/2205身份全保留；完整root2229/18257、candidate2251/17826 strict0，三通知聚焦81/584和81/472。双树真实后台各9页面/9操作组，root30/candidate29资源；五原生worker/四array邮件/失败后重试attempts2,1,1,1/事务层级0/密文全清/queue0，独立CLI终态核对。MySQL8.4.11/RR分别2组4worker，释放前和500ms后全部LOCK WAIT，旧行及索引语义保留、同状态一意图/不同转换两意图，状态数据down保留。全部自有8200服务停止，13316正常shutdown；根112/候选129依赖没有调整，未访问实际数据库/SMTP、未部署或切根。详见[完整范围、证据与重放](order-status-mail-outbox.md)。

## 第二十六批：七资源删除事务与真实永久删除验收

默认Dcat DELETE在回收记录上会forceDelete。真实root仅goods/3*权限通过3,4路径永久删除两行和两图，七资源守卫新增逐ID DELETE核对并保留整路径原检查；非法/重复key400，原认证CSRF/配置语义保留。应用TransactionalRepository沿用父预载，模型连接txn内全部重查/主键排序锁定，false取消/缺员/异常全部SQL回滚，软硬阶段变更停止，图片快照在事件前冻结、commit后才用原字段清理。两级级联改为源模型connection，保留分类bulk与卡密独立回收语义。Emailtpl原恢复动作补缺失onlyTrashed筛选，真实ID20由0行变1行。

69项删除421断言+原权限64/989，两平台聚焦133/1410；完整root2298/18678、candidate2320/18247 strict0，原2229/2251身份全保留仅新增69。真实七资源各43页面/26组/23权限输入检查，root38/candidate37本地资源，实际47次deleting均txn内；第二项软/硬删故障保留七表/关系表/全盘hash，最终goods4/11/12三图保留，独立CLI全字段一致。提交后的文件清理异常会留下已删除DB/残余文件，外层永久批删先失败，不宣称跨系统原子。全部自有8201停止、112/129锁图不变，未改vendor/发布资源/真实DB或部署。详见[精确证据和重放范围](admin-deletion-compatibility.md)。

## 第二十七批：LinuxPCNTL与用户态进程托管兼容

根112/候选129包图和Composer锁未调整；现有WSL Debian13用户态仅解包同版PHP8.5.11与校验过的动态库、Supervisor4.3.0公开源码。实际运行发现PRC backward时区别名缺失导致Carbon/worker异常，应用改标准Asia/Shanghai并补冬夏UTC+8永久回归，修前双树各1fail/1assert。完整Windows root2299/18681、Windows与Linux候选2321/18250 strict0全部通过，原全部身份保留且只新增一项。

三平台A–E2452checks/24PID；Linux失联由真实SIGKILL/termsig9实证，Supervisor最终777checks/三worker/fourtask验收实际SIGTERM在途排空、下一job未领取、queue:restart/cache与autorestart新PID、shutdown0/jobs0/failed0。审计发现的proc_close清理P2以共享有界helper与实际忽略TERM探针5.062秒修复。原任务60秒timeout真实触发60.064623秒JobTimedOut与WorkerStopping、worker原生self-SIGKILL，独立四进程472checks验证失败payload保留、lease到期补发新job/attempt2/204一次，最终queue0/lease清空/原failed1保留。容器镜像、系统cron、application身份及正式部署DB/资源/SMTP仍独立待验，见[完整Linux证据与重放](linux-worker-compatibility.md)。

## 第二十八批：容器停止契约与PID1运行身份

现有Compose缺外层停止宽限，Docker Linux默认10秒会在Supervisor90秒/原job60秒排空前强杀；补SIGTERM/120s，原路径端口及挂载保留。官方Compose5.6.0项目内下载校验，双树真实config模型各五检查passed；短10s/硬kill/缺grace及CLI30-job60-wait45四反例各仅相应失败，CLI30/job60/wait90正常pass。当前7.4镜像manifest/config和启动/cron/include实际文件证明原路径有效、SIGQUIT也支持，没有猜测性修改；该旧镜像Python2.7与当前namespacePython3.13/Supervisor4.3分开记录。

新增普通用户私有user/mount/PID namespace，合成applicationUID/GID1000、真实SupervisorPID1与原worker，Host254→NS1/Host256→NS3精确映射后SIGTERM。持10.534秒时任务未complete/下个attempt0，10.615秒释放原15秒gate后204一次/txn0/worker0/daemon0，297checks/cleanupempty/remaining0。源码及Host账户hash一致，三份新PHP74lint和shell验证passed。业务/依赖清单和112/129包图未变，核心完整三平台回归复用第39轮；完整OCI镜像、Docker实际EngineStopTimeout/crontick、stock旧Supervisor权限切换及正式根框架切换仍待验，见[精确证据与重放](container-stop-compatibility.md)。

## 第二十九批：候选真实分钟调度与有界派生清理

新增候选原生 `schedule:work` 集成验收，原AppKernel事件、实际mutex和worker保留。两次墙钟入口相隔59.748秒，854项检查通过；仅到期订单返券一次，第二分钟SQL、两条任务与300秒租约保持一致，随后204回调和array邮件各一次、事务级别0、attempt1，daemon/worker均exit0。独立重开SQLite及14类源码/15进程身份复核155项通过。退出等待和清理窗口持续扫描专属SID/PGID，覆盖失父与晚生后代；故障注入实测根TERM后fork并立即失父的抵抗子5.307秒内回收。保持testing隔离并显式启用原生信号注册接口，短信号183项通过。

系统cron私有命名空间预检94项通过后在setgroups继承deny处退出，0tick，不作为cron分钟验收。15份生产配置/依赖源未变，根112包及候选129包保持原锁图，完整核心回归复用第39轮。没有切换正式调度入口或根框架；精确重放、运行与未验范围见[调度验收](scheduler-compatibility.md)。

## 第三十批：候选真实 HTTP SAPI

新增同补丁PHP8.5.11 FPM与Nginx1.26.3私有运行验收，公开包按索引SHA256/尺寸校验解包，不安装系统服务。原前台入口字节保持，独立base/SQLite/cache/session/storage下17客户端=Nginx17、FPM动态13按唯一ID/method/URI/status对应，145checks与父独立176checks通过。Vue原资源SHA/MIME、原CSRF419/成功文件会话、四代理头、严格JSON500、maintenance503/recovery及原生SIGQUIT0闭合；启动后故障exit1，六原身份与四句柄回收、两监听关闭。25份生产配置/源及依赖hash保持，根112/候选129图与三平台完整回归复用第39轮。实际范围与重放见[HTTP运行验收](http-runtime-compatibility.md)，根依赖和完整部署仍继续验收。

## 第三十一批：新旧文件状态与后台组件弃用

同key/cookie名/默认PHP会话格式下，旧根与维护期候选42独立进程实际通过260checks，32原Kernel请求含真实Dcat登录/CSRF/remember/网格/退出；旧cache/session、递归类型、ViewErrorBag、反向更新和退出前旧session-only/remember-only撤销通过，独立12缓存/32会话源字节及SQL323checks。严格网格请求暴露六条实际PHP8.5动态属性弃用，两个永久真实组件用例修前ErrorException后，应用通过原容器/批删配置声明属性保持父方法，未改vendor或屏蔽错误。完整root2301/18693、Windows/Linux候选2323/18262重跑通过，112/129包图保持，根框架继续保留；[范围、证据与重放](cross-version-state-compatibility.md)。

## 后续限制

PHP7.4是旧版比较基线，不代表目标平台升级完成。Laravel6、后台组件、支付SDK和旧邮件系统仍需逐批迁移。原minimum-stability为dev；广泛update预演选中了多个开发分支，因此本批仅安装明确指定的Carbon稳定版，不采用该广泛解析结果。

当前仍有已知依赖审计告警；首次预演报告16个包46条公告，并非本批消除了这些告警。后续需重新审计、替换过时依赖并在目标PHP版本执行平台检查和完整回归。
