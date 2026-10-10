# 框架迁移与正式根依赖

第54轮[正式根切换](root-dependency-promotion.md)已完成PHP8.5.11 / Laravel13.35.0 / Dcat X2.0.4 / PHPUnit13.4.1固定129包及根autoload/hooks；Windows/Linux根完整2331/18310同身份通过。下文第53轮及以前为历史候选与旧根比较证据，真实商户部署、完整Engine和系统cron单独记录。

第39轮时实际根应用为Laravel6.20.42/Dcat2.0.24-beta，PHP7.4作为旧版比较运行时。已移除旧极验框架包并接入[本地V3适配层](geetest-adapter.md)，以及移除旧汇率包装并接入[独立报价与金额换算](currency-conversion.md)。下列组合已完成独立依赖安装、当前应用启动及全部Unit/Feature与SMTP协议回归。根项目框架和后台仍保留，废弃PayPal SDK已移除并由[旧对象协议适配](paypal-v1-compatibility.md)替代；PHP8.5.11/Laravel13.35.0/PHPUnit13.4.1候选2321项测试已在Windows和Linux8.5.11严格通过（第39轮新增标准时区回归，第36轮64项显式资源权限与恢复、第37轮46项人工状态邮件、第38轮69项删除回归），[核心后台/资产浏览器](admin-compatibility.md)已双框架验收，[四角色扩展恢复/真实上传](admin-extended-compatibility.md)已双树22/28/13，[人工状态邮件/原生worker与MySQL锁竞争](order-status-mail-outbox.md)已双树实测，[七资源批删/永久删除](admin-deletion-compatibility.md)已双树43页面/26操作组/23拒写或输入检查，[LinuxPCNTL/用户态Supervisor](linux-worker-compatibility.md)已实际补验，真实部署数据库、其余管理操作及容器/系统cron运行环境仍待验收，详见[隔离运行范围](../tests/Compatibility/Laravel12/README.md)。

第44轮完成[独立生产发布验收](production-release-compatibility.md)：固定候选锁100运行包/29开发包缺席，原Composer三命令37checks、原Artisan六命令与真实Nginx/FPM189checks/32网络请求/10动态事件通过；候选850公开资源完整路径与字节一致，独立重开源码/锁reference/三方流量/SQL/进程端口6709checks通过。root-local App/helper autoload、生产config/routes缓存、无外部App/vendor或开发类加载均据实际运行记录；这是私有UID1000发布目录，整体根切换、OCI/Engine、systemcron及真实部署仍待验。

第45轮完成[优惠券原表单商品关联](admin-coupon-compatibility.md)：真实多选新建/替换/清空和两关系INSERT故障原子回滚，双树浏览器各11checks、完整Windows旧根2305/18728及候选2327/18297通过，Linux新四例4/35聚焦通过；原case全部保留、应用与112/129锁图不变，继续其他正式切换验收。

第46轮[独立no-dev生产交易链](production-transaction-compatibility.md)完成实际FPM原表单输入三订单、300s原任务载荷、原worker early处理与原过期双遍一次补偿，472checks/56HTTP/34FPM及父24503checks通过。原加密Cookie篡改、UUID失败记录log/forget和15原SQL快照覆盖此发布运行条件；保持根切换/OCI等后续实际门槛。

第47轮[固定PHP8.5镜像构建准备](modern-image-preparation.md)完成官方native摘要、原接受源/850资产和固定100运行包锁的独立1270文件context；实际Compose模型与全集合/source/tag链3823checks通过，错锁与同步缺源负控制实际失败。保留INPUTS_VERIFIED而非镜像成功：当前无Engine，完整构建、PHP扩展、stock权限、cron与停止计时待实跑。

第48轮[原队列双框架迁移](cross-version-queue-compatibility.md)在原整数failed表/database驱动下实际消费双向原payload，302checks/26独立Kernel及父18229复核通过：两方向三次完整回滚、两原整数ID重试与退券一次；旧无uuid载荷的UUID provider负控制独立记录。启动期真实验证码PHP8.5弃用已应用侧适配并保留原Provider FQN/发布/去重，Windows完整旧根2309/18741与候选2331/18310重跑通过，Linux验证码5/18通过。第47镜像目录保留旧验收源；当前App已由新接受发布生成3832检查/1273文件的新context，仍待实际构建运行。最终no-dev真实新观察发布543/57HTTP/35FPM和强制父27252通过，原配置二次App另58正负控制保原cache字节。

第49轮[完整Linux与最终发布观察](complete-release-observation.md)通过Linux2331/18310，逐用例与Windows候选同身份；全新no-dev发布545/57HTTP/35FPM，config及route二次App均纳入，全部17PHP角色/35FPM的强制独立复核37157通过，原源码和两锁未变。

第50轮[固定镜像原CLI验收](native-image-runtime.md)实拉20层/完整DiffID链、原镜像PHP/Composer21平台、冷引导144与独立37993通过；单UIDnamespace0→外部1000，仅CLI范围，原控制器PIDnamespaceFAIL及独立恢复保留，Engine/stock降权/服务cron仍待。

第51轮[完整CLI与原Web服务](original-image-http.md)实际冷CLI144/完整控制器PASS/默认39274、Web33HTTP/11FPM8095/独立44281。审计发现的清单遗漏、产物串用与父角色/log全集/PID绑定已补核；原App/双锁保持接受版本，Engine/多UIDstock权限/cron仍待正式根切换。

第52轮[原镜像队列与Supervisor](original-image-queue.md)通过63383内部/53317独立，15队列事件、16PHP、17原命令、4worker和27原SQL快照覆盖三次回滚/原UUIDretry、队列重启与两次调度子命令。原整数失败driver及两锁保持，原application多UID权限/systemcron/Engine仍待。

第53轮[原application身份与切换范围](original-image-application-user.md)验证UID/GID1000、五类能力零与NoNewPrivs1，原程序user=application保持，63404/独立53614通过。另只读核明正式本地根升级须同批根清单/锁、Dockerfile、PHPUnit并实际执行原hooks/跨平台测试；运营Engine/stock降权/systemcron/真实商户验证分别记录，不作为可逆本地代码升级的额外前置门槛。

## 已核实的兼容限制

| 组件 | 当前限制 | 后续迁移 |
| --- | --- | --- |
| dcat/laravel-admin | 原最新版2.2.3-beta仅声明到Laravel10 | Dcat X维护分支；已安装2.0.4，声明PHP8.3+/Laravel12/13并在Laravel13实测，保持Dcat\Admin命名空间 |
| germey/geetest | PHP7、Illuminate不超过7 | 本轮已用应用适配层替换并移除依赖 |
| fideloper/proxy | 旧独立代理中间件 | 已接新框架内置父类并保留旧树Fideloper父类，显式四字段转发掩码/可信与非可信请求实测通过 |
| amrshawky/laravel-currency | 4.0到Laravel8，最新6.0到Laravel10 | 已移除包装及失效核心，接入独立Frankfurter/招行协议和精确换算；可选订单报价冻结与收款核对已接入，Stripe22/PaymentIntent已迁移，PayPal可选Orders V2协议已接入，现代SDK仍待根框架兼容切换 |
| yansongda/pay 2.x | 第54轮正式根已切到已验Pay3.7.20；旧Pay2仅保留在回退目录 | [支付宝双SDK适配/微信V2协议](cny-gateways.md)已通过真签名与双框架路由；普通RSA模式用应用StartPlugin保留SDK3官方签名/验签，现有微信密钥继续V2，V3需独立凭据与验收 |
| paypal/rest-api-sdk-php | 已实际移除1.14.0；第54轮正式根已安装官方ServerSDK2.4.0，旧根Symfony4冲突是历史限制 | 旧链接通过[原生v1协议](paypal-v1-compatibility.md)恢复，现代可选[Orders/Capture](paypal-orders.md)保留唯一对象与实收guard；开启V2前仍需清账旧v1对象 |
| stripe/stripe-php | 原7.84直接Charge/Source和旧前端 | 已实际升级22.0.0，可选唯一PaymentIntent/Payment Element及收款守卫已接入，可选签名Webhook/持久收件/后台对账已接入，PayPal现代协议已接入可选实现，新框架运行及SDK替换仍待迁移 |
| Swift Mailer | Laravel9+邮件实现已更换 | 已完成双框架[Symfony适配与outbox验收](mail-runtime.md)：worker配置刷新、取消/空信封/传输异常重试、显式TLS及HELO降级阻断；真实SMTP和收件箱仍待验证 |
| facade/ignition/collision旧版本 | 旧框架工具链 | 移除或选择目标框架相应版本 |

信息来源：[Laravel支持政策](https://laravel.com/framework/docs/releases)、[Dcat X版本声明](https://packagist.org/packages/dcat-x/laravel-admin)、[Dcat X 2.0发布说明](https://github.com/dcat-x/dcat-admin/releases/tag/v2.0.0)、[PayPal官方服务器SDK](https://packagist.org/packages/paypal/paypal-server-sdk)，以及实际Composer show/update输出。Laravel12安全修复至2027-02-24，Laravel13 bugfix至2027年第三季度、安全修复至2028-03-17；第34轮当前隔离候选已安装Laravel13.35.0/Dcat X2.0.4/Tinker3.0.2并实际验收，见[维护期框架组合](laravel13-compatibility.md)。[PHP8.5/PHPUnit13](php-runtime-compatibility.md)保留第33轮Laravel12证据；[正式本地根切换](root-dependency-promotion.md)已验，运营部署环境和扩展操作分别验收。

第40轮现有Compose补SIGTERM/120s外层停止宽限，官方模型双树通过，短宽限/SIGKILL/缺宽限/CLI30与job60分歧反例按契约失败；实际Linux namespace里PID1及applicationUID1000持原任务超过10秒后单次排空成功，297checks。镜像cron/include路径经当前实物核对有效；[完整证据与Docker未验边界](container-stop-compatibility.md)区分源文件检查、namespace进程与完整镜像/Engine/cron实跑。核心2299/2321与112/129锁图源未变，复用第39轮完整验证。

第41轮新增[真实分钟调度验收](scheduler-compatibility.md)：原生Laravel分钟循环、原事件与互斥锁、通知租约和worker收尾分别记录。系统cron的单UID命名空间setgroups前提尚未满足，仍保留为运行环境未验项。

第42轮新增[候选真实Nginx/FPM请求验收](http-runtime-compatibility.md)：PHP8.5.11 FPM原HTTP入口145checks、17客户端/Nginx记录与13动态事件严格关联，Vue资源/CSRF/文件会话/代理/维护恢复通过，原生退出与启动后故障回收均复核。25份保护源/配置/依赖hash保持，完整核心验证复用；这一项与镜像、权限切换、系统cron及正式部署分别记录。

第43轮新增[新旧文件状态双向验收](cross-version-state-compatibility.md)：42独立PHP进程/32原Kernel请求/260checks、独立原缓存与会话字节323checks，旧后台身份/CSRF/remember和退出后旧cookie撤销通过；修复实际上传器与默认批删六条PHP8.5动态属性弃用，应用原扩展点继承方法不改vendor。完整旧根2301/18693、Windows/Linux候选2323/18262重跑通过，112/129锁图保持。

## 可复现安装与验收

[候选清单](../tests/Compatibility/Laravel12/composer.json)现包含实际App/Testsautoload及独立启动/测试入口，安装时禁用scripts/plugins。使用经官方SHA256校验的便携PHP8.5.11与完整独立INI，不修改系统配置；候选PHP约束^8.4，本次仅实测8.5。

```powershell
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini E:/php/composer.phar install --no-scripts --no-plugins --no-interaction --working-dir=tests/Compatibility/Laravel12
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Compatibility/Laravel12/probe.php
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Compatibility/Laravel12/vendor/bin/phpunit -c tests/Compatibility/Laravel12/phpunit-core.xml
```

最新候选实际安装129个包（100运行/29开发；原解析133，Payjs/旧测试工具链/DBAL链已逐步替换），关键候选Laravel13.35.0、Dcat X2.0.4、Tinker3.0.2、Yansongda3.7.20、PayPal Server SDK2.4.0、Guzzle7.15.5、Symfony Mailer7.4.19、Carbon3.14.2、Mews Captcha3.5.1。候选清单移除了旧极验、Fideloper、旧汇率包装/核心和旧PayPal SDK；汇率调用已完成应用内迁移，Stripe现代SDK及PayPal现代协议已逐步适配；独立新框架已实际启动，异常/代理/维护和双框架邮件兼容均已通过，全部常规回归、SMTP、真实CSV/XLSX与12项运行边界合计2140项已在[PHP8.5/Laravel13/PHPUnit13严格模式](laravel13-compatibility.md)通过；支付宝已验证SDK3公钥适配，微信V2在双框架保留。真实商户账户、扩展后台操作及目标数据库仍待验证；核心后台页面/资产/保存已通过[双框架浏览器验收](admin-compatibility.md)。候选Stripe已同步实际安装的22.0.0，新旧付款路径在PHP7.4已验收；候选常规全量测试已通过，正式切换的运行环境验收仍待闭合。Payjs旧包装已由独立协议替换并从两树移除；二维码精确约束仍保留，解析可行不等于完整运行环境验收。

第二十三轮已实际安装133个包并保存候选锁，Composer validate/check-platform-reqs通过；没有忽略平台要求或关闭公告阻断。实际根项目仍为旧依赖树；此前11包30条公告是旧基线记录，独立新候选不代表根项目告警已经消除。第二十四轮闭合剩余13项旧PayPal失败并移除根SDK：最终PHP7.4根全量557/5060、新候选全部Unit/Feature加6项SMTP协议563/4622通过。原核心XML现发现全部常规测试，不再排除混合类。新增旧执行尝试在订单行中持久保存，未知响应即使GET仍created也只查询恢复，禁止重复执行与另建付款对象；前后事务都重查网关/价格/凭据/报价。第二十五轮迁移两处Yansongda调用：SDK2/3支付宝及独立微信V2原凭据协议，实际回调验签后通过订单/渠道事务守卫发货，正确XML确认微信通知。新增228项，本轮最终根785/7188、候选791/6734通过。同轮续接完成[Payjs原生协议](payjs-compatibility.md)，移除两树包装各0新增/0升级/1移除，根112包/候选132包，显式DOM扩展和当前平台/锁检查通过；最终根1018/8281、候选1024/7827，原生三网关71/575。第二十六轮完成[Mapay/Coinbase旧回调](legacy-gateway-callbacks.md)，真实交易号和付款数组判定修复，共享方法快照、独立字节协议、原生路由与高精度/201样例通过；新增242项，最终根1260/10883、候选1266/10427。第二十七轮完成[易支付/V免签/Paysapi原表单协议](form-gateways.md)，业务成功/类型匹配、原始签名字节、转义结账表单及共享锁内守卫验收；独立审查发现 string0 被公共交易去重误当空值，九项真实签名回归先复现再修复。新增238项，最终根1498/13051、候选1504/12595。第二十八轮完成[旧Epusdt/TokenPay JSON协议](crypto-gateways.md)，四个已签名未付/过期仍履约先复现再修复，法币/资产匹配、十二种入口和非空用户标识通过；原JSON有界解析和金额数值编码不依赖浮点序列化，审查发现的8KiB合法长字段JIT栈问题以五项解析及六项原生回归修复。新增331项，最终根1829/15308、候选1835/14850，无排除通过。第二十九轮完成[Bepusdt原协议及真实交易](bepusdt-compatibility.md)，三条关闭渠道/法币/方式变化回归先失败后修复，原JSON/锁内收款及精确ok应答通过；新增271项，最终根2100/16764、候选2106/16306，无排除通过。第三十轮[隔离MySQL验收](mysql-concurrency.md)在根PHP7.4/Laravel6与候选PHP8.2/Laravel12各通过36组/85进程及七份前向迁移，覆盖共同回执守卫和旧v1唯一预留/GET恢复；事务信息缓存刷新及不同引用Oracle问题已修复后重放。实际部署数据库/后台/测试工具链与正式PHP/框架组合仍待验收，之后才切换根框架。

第三十一轮完成[核心后台兼容与真实浏览器验收](admin-compatibility.md)：根/候选各43次页面检查、8个统计响应、56个本地资源，分类/商品/文本导入/系统设置实际保存、认证/CSRF/退出和移动导航通过。实际复现并修复七模型日期变UTC ISO及Flysystem3文本导入写入后delete(null)异常，同时修正跨平台换行及移动入口拆字。新增18项回归，全量根2118/16948、候选2124/16490无排除通过，后台主JS/CSS响应字节与各自安装组件一致。真实部署表结构、扩展后台操作/资源发布、现代PHPUnit及正式平台/worker生命周期仍待验收；根框架不切换。

第三十二轮完成[PHPUnit9/11共存验收](phpunit-compatibility.md)：候选升11.5.57，167个数据驱动方法使用单行属性并保留旧注解，133providers全部static，两个result final冲突及依赖helper已适配。完整根2118/16948、候选strict2124/16493通过；发现清单2124项逐项一致，三条计数差异来自新版mock参数验证。候选131包、102运行包版本/source reference不变；validate/platform、实际158routeprobe和29PHP7.4语法通过。当前PHP8.2与PHPUnit11为比较台阶，最终受维护PHP/框架/测试平台仍需验收。

第三十三轮完成[PHP8.5/PHPUnit13维护版平台验收](php-runtime-compatibility.md)：候选最低PHP^8.4、实际8.5.11/13.4.1，102运行包完整元数据仅OpenSpout4.28.5→4.32.0，其余101不变；总131包。实际NAN/nullable/PDO与runner配置/mock弃用修复，原2124发现逐项保留，真实CSV/XLSX新增4项。根2118/16948、候选strict2128/16529通过；PHP8.5真实后台43检查/8统计/56资源再次通过。正式框架周期、同轮PHP8.5 MySQL候选36组/85worker及七迁移真实通过，全部等待前后核对；实际部署schema、扩展管理和worker/scheduler生命周期继续验收。

第三十四轮完成[维护期Laravel13/Dcat X2候选](laravel13-compatibility.md)：13.35.0/2.0.4/Tinker3.0.2实际安装，129包=100运行+29开发，1新增/4版本更新/3移除与同版phpdotenv URL变动逐项记录。完整原2128/16529严格通过并保持全部身份；新增12项HTTP/文件缓存/会话真实边界后最终2140/16574严格通过。真实后台43/8/56与MySQL36组/85worker/七迁移及全部锁等待再次通过，临时服务已结束。官方Laravel13 bugfix至2027年第三季度、安全修复至2028-03-17，本地维护周期组合已闭合；部署schema、扩展管理操作和worker/scheduler/container生命周期继续验收。


