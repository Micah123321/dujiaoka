# Laravel 13 与 Dcat X 2 维护期候选验收

第34轮当前隔离候选实际运行 PHP8.5.11 / Laravel13.35.0 / Dcat X2.0.4 / Tinker3.0.2 / PHPUnit13.4.1，完整2140项测试 / 16574次断言严格通过，真实后台与MySQL锁竞争也已重放。根Laravel6依赖继续保留；本轮仅更新隔离候选和候选专属边界测试，根完整2118/16948复用[第33轮证据](php-runtime-compatibility.md)，未宣称本轮重新执行旧根套件。

第35轮新增共享真实数据库worker退避回归并修复30秒间隔兼容；当前完整候选2141/16588、旧根2119/16963均实际重跑通过，正式双树还各完成五类独立PHP worker/scheduler生命周期场景、24子进程与原生mutex持有释放，见[队列生命周期与本轮证据](queue-lifecycle.md)。以下框架安装/后台/MySQL证据保留第34轮范围。

第37轮完整候选2251/17826、旧根2229/18257实际重跑通过；新增46项人工状态邮件回归，三通道聚焦候选81/472、旧根81/584。两树真实后台各9页面/9操作组、五个原生数据库worker接受四封array邮件，失败补偿后密文清空/队列0；新迁移在MySQL8.4.11真实保留旧记录，两组/四worker锁等待核对同状态单意图、不同转换双意图，见[人工状态邮件与原生验证](order-status-mail-outbox.md)。第36轮64项资源权限和扩展浏览器22/28/13、核心43/8/56保留各自版本证据，见[扩展后台](admin-extended-compatibility.md)。

第38轮完整候选2320/18247、旧根2298/18678通过，新增69项删除回归并保留全部原身份。七资源逐ID DELETE权限、事务批删/模型取消、锁前软硬状态变化停止、源连接级联及提交后文件清理已双平台133/1410聚焦与实际43页面/26操作组/23拒写或输入检查验收，见[批删与永久删除证据](admin-deletion-compatibility.md)。没有把该43页面与第36轮原核心43/8/56混为同一次重跑。

第39轮Linux原生运行暴露PRC时区别名缺失并修复为标准Asia/Shanghai，新增一项冬夏UTC+8回归。Windows旧根2299/18681、Windows及Linux同版8.5.11候选2321/18250全部严格通过；通用五场景在三套平台各2452次检查、24个childPID通过。LinuxSupervisor实际stop/在途排空/start/queue:restart/autorestart/shutdown与原生信号另有[独立证据和重放](linux-worker-compatibility.md)。该证据不代替Docker/系统cron或生产环境验收。

第43轮[双框架文件状态与后台登录延续](cross-version-state-compatibility.md)闭合此前旧cache/session跨版本缺口，42独立PHP进程/260checks及323项原字节复核通过。原Dcat上传器/默认批删六条PHP8.5动态属性弃用已用应用扩展声明属性修复；新永久组件两用例修前失败、修后通过，完整旧根2301/18693及Windows/Linux候选2323/18262严格重跑通过。

第44轮完成[独立生产发布验收](production-release-compatibility.md)：固定候选锁100运行包/29开发包缺席，原Composer三命令37checks、原Artisan六命令与真实Nginx/FPM189checks/32网络请求/10动态事件通过；候选850公开资源完整路径与字节一致，独立重开源码/锁reference/三方流量/SQL/进程端口6709checks通过。root-local App/helper autoload、生产config/routes缓存、无外部App/vendor或开发类加载均据实际运行记录；这是私有UID1000发布目录，整体根切换、OCI/Engine、systemcron及真实部署仍待验。

第45轮[优惠券商品关系写入](admin-coupon-compatibility.md)新增四永久原表单回归，完整旧根2305/18728及Windows候选2327/18297通过；最终金额按精确分断言的Windows/Linux候选四例4/35通过，真实Chrome多选关系[1,2]→[2,3]→空及原422/419无写入、两原INSERT故障事务回滚均有独立证据。Linux全量保留第43轮2323/18262范围，没有将聚焦通过称为完整2327重跑。

第46轮[生产缓存独立发布交易](production-transaction-compatibility.md)在固定100 runtime/29 dev absent的另一个新发布通过原Nginx/FPM下单→原队列→原worker→双expire补偿，472checks/56client/34FPM、父独立24503checks；真实加密Cookie/MAC负控制及UUID failed provider记录兼容通过。只改变ProductionRelease验收夹具，核心/App/vendor/锁保持，复用45完整结果，未称Linux全套又重跑。

第47轮[候选镜像准备](modern-image-preparation.md)固定官方linux/amd64 PHP8.5.11 config对应manifest，建立绑定原验收source与850资产的独立1270文件context，3823实际输入/model checks及缺源/错锁负控制通过。candidate锁原字节与100运行包引用保留；当前未构建/运行image，实际根PHP/framework未切换。

第48轮[原队列双框架迁移](cross-version-queue-compatibility.md)在原整数failed表/database驱动下实际消费双向原payload，302checks/26独立Kernel及父18229复核通过：两方向三次完整回滚、两原整数ID重试与退券一次；旧无uuid载荷的UUID provider负控制独立记录。启动期真实验证码PHP8.5弃用已应用侧适配并保留原Provider FQN/发布/去重，Windows完整旧根2309/18741与候选2331/18310重跑通过，Linux验证码5/18通过。第47镜像目录保留旧验收源；当前App已由新接受发布生成3832检查/1273文件的新context，仍待实际构建运行。最终no-dev真实新观察发布543/57HTTP/35FPM和强制父27252通过，原配置二次App另58正负控制保原cache字节。

第49轮[完整Linux与发布观察](complete-release-observation.md)补齐当前Linux2331/18310；新545发布包含config/route两次引导，全部17PHP记录与35FPM强制独立复核37157通过，外部观察适配保持原供应商缓存处理和源码/锁版本。

## 维护周期与隔离边界

[官方Laravel13支持政策](https://laravel.com/docs/13.x/releases)列出PHP8.3–8.5、bugfix至2027年第三季度、安全修复至2028-03-17；[原文行](../tests/.runtime/laravel13-support-34.txt)来自官方13.x发布文档，查询时间2026-10-10。PHP8.5活跃维护至2027-12-31、安全修复至2029-12-31；PHPUnit13常规bugfix至2028-02-04，[上一阶段官方来源和便携包SHA256](php-runtime-compatibility.md)继续适用。候选清单最低PHP^8.4，本轮实际仅验收8.5.11。

[候选目录](../tests/Compatibility/Laravel12/README.md)仍保留Laravel12历史路径，避免改变已经验证的环境、缓存、storage隔离oracle；目录名不表示当前框架版本。真实[probe](../tests/Compatibility/Laravel12/probe.php)已报告13.35.0、Dcat X2.0.4、158路由、SQLite :memory:、独立storage及ArrayTransport。包清单改为通用framework-compatibility-runtime名称，候选autoload继续加载真实App与共享Tests。

## 最小联合依赖更新

[实际清单](../tests/Compatibility/Laravel12/composer.json)使用Laravel^13.0、Dcat X^2.0及Tinker^3.0。首次实际预演证明锁定Tinker2.11.1只接受Illuminate至12；在现有^2.5范围内解锁也仍失败，因此按[官方升级指南](https://laravel.com/docs/13.x/upgrade)调整Tinker主版本，随后最小预演及安装成功。没有忽略平台要求、关闭公告检查或修改vendor。

```powershell
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini E:/php/composer.phar update laravel/framework dcat-x/laravel-admin laravel/tinker --with-all-dependencies --minimal-changes --no-scripts --no-plugins --no-interaction --working-dir=tests/Compatibility/Laravel12
```

实际1新增、4版本更新、3移除；运行102→100，开发29不变，总131→129：

| 依赖 | 原版本 | 当前版本 |
| --- | --- | --- |
| Laravel | 12.69.3 | 13.35.0 |
| Dcat X | 1.2.2 | 2.0.4 |
| Tinker | 2.11.1 | 3.0.2 |
| spatie/eloquent-sortable | 4.5.2 | 5.0.1 |
| symfony/polyfill-php86 | 无 | 1.43.0 |
| doctrine/dbal、doctrine/deprecations、psr/cache | 4.5.0 / 1.1.6 / 3.0.0 | 联合依赖已移除 |

逐包完整锁元数据与[升级前完整锁](../tests/.runtime/laravel12-full-baseline-34.json)核对：另94个运行包和全部29开发包完全一致。phpdotenv5.7.0版本与reference不变，仅source/dist URL由vlucas组织迁移到phpdotenv组织；该元数据变化单独列出，未把其描述成完整metadata不变。见[精确对照](../tests/.runtime/laravel12/laravel13-dependency-diff-34.json)。OpenSpout4.32.0、easy-excel1.0.2以及既有支付/邮件SDK版本继续保留。Composer validate/check-platform-reqs通过，发行QRCode2.0.0精确约束提示保留；安装报告候选无公告，不表示根旧依赖告警已经消除。

## 回归与真实运行证据

| 验收 | 实际范围 | 结果 |
| --- | --- | --- |
| 升级后原套件 | 2128测试 / 16529断言 | strict exit0，22.476秒 |
| 新运行边界 | 12测试 / 45断言 | 已纳入永久XML |
| 最终完整套件 | 2140测试 / 16574断言 | strict exit0，22.758秒 |
| 真实后台浏览器 | 43页面检查 / 8统计请求 / 56资源 | 原HTTP内核、真实写入与资源字节核对通过 |
| 真实MySQL8.4.11 / RR | 36组 / 85worker / 7前向迁移 / 15 InnoDB表 | 全部等待前后逐项核对通过 |

JUnit：[原套件](../tests/.runtime/laravel12/laravel13-first-34.xml)、[最终完整](../tests/.runtime/laravel12/laravel13-final-34.xml)。严格模式保留failOnAllIssues/displayDetailsOnAllIssues及空suite失败；无warning、deprecation、notice、risky、skip、incomplete。Injected queue/notification failure日志来自既有失败恢复回归，exit0。未采集coverage。独立只读审查核对完整前后包元数据、真实中间件短路及持久文件目录边界，未发现可复现P1/P2；该第34轮审查未代跑测试，当时未验证升级前旧缓存/会话的跨版本读取；第43轮另按上文双向独立进程与原字节证据补验。

[原2128发现清单核对](../tests/.runtime/laravel12/laravel13-discovery-core-34.json)无缺失/新增/重复；[最终2140对照](../tests/.runtime/laravel12/laravel13-discovery-final-34.json)仅新增12项RuntimeBoundary案例，原有用例逐项保留。新的[边界回归](../tests/Compatibility/Laravel12/RuntimeBoundaryCompatibilityTest.php)直接实例化应用CSRF中间件，临时退出testing环境并断言未走unit-test短路；涵盖same-origin来源、cross-site/same-site无token阻止、正确/错误token、缺来源header及pay/*例外。使用进程内HTTPS Request，按已安装13.35实际header/token契约断言，不宣称网络TLS或HTTPS浏览器验收。

另通过全新CacheManager两实例，验证实际文件缓存保存及重读settings数组、catalog嵌套数组和标量，保持中文、bool、integer0/string0及金额字符串类型；SessionManager两实例与真实file handler保存/重读同ID的登录整数、中文、string0及CSRF token。均在专用随机runtime子目录创建新文件，finally恢复配置并仅删除本次目录。这是第34轮同版本新文件的重读；跨版本旧session延续已在第43轮单独验收，不合并为同一次运行。现有cache/session配置和应用别名前缀继续保留，未引入对象缓存白名单或迁移会话格式。

[本轮真实后台报告](../tests/.runtime/admin-candidate/browser-laravel13-final-34.json)覆盖认证、无token419、实际分类/商品保存、文本导入、系统设置文件缓存持久化、移动导航及退出；没有伪造本地成功响应。桌面1440px与移动390px截图已实际查看，当前Dcat X2布局与核心交互通过，详见[范围与重放](admin-compatibility.md)。

[本轮数据库summary](../tests/.runtime/mysql-concurrency/run-20261010-024647-3e7425d9/summary.json)记录PHP8.5.11/Laravel13.35.0/MySQL8.4.11/RR；36份lock-observed全部核对，合计85worker，释放前及继续持锁500ms后等待数均全员、观察间隔150ms，见[精确核对](../tests/.runtime/laravel12/laravel13-mysql-proof-34.json)。七份真实前向迁移及十五InnoDB表通过，使用原有marker实例，未重新初始化数据目录。后台服务已停止，数据库正常shutdown，8198与13316均确认无监听；[数据库范围](mysql-concurrency.md)继续约束这份证据。

## 当前重放与剩余范围

使用经官方SHA256验证的PHP8.5.11及完整独立INI，从工作区根执行：

```powershell
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini E:/php/composer.phar install --no-scripts --no-plugins --no-interaction --working-dir=tests/Compatibility/Laravel12
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini E:/php/composer.phar check-platform-reqs --no-plugins --working-dir=tests/Compatibility/Laravel12
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Compatibility/Laravel12/probe.php
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Compatibility/Laravel12/vendor/bin/phpunit -c tests/Compatibility/Laravel12/phpunit-core.xml
```

浏览器及数据库使用对应文档的当前PHP8.5入口。旧第30/31/32/33轮报告保留各自运行版本；当前重跑不要覆盖其固定文件。此批没有修改共享应用代码或根依赖，没有部署、提交、推送或发布后台资源。

当前候选维护周期与本地平台组合已验证，第35轮两套原生worker/scheduler/restart/失联恢复及第36轮四角色商品批删恢复、卡密还原和真实上传，以及第37轮人工状态邮件/原生worker/新迁移MySQL锁竞争已经补验；剩余为真实部署版本/表结构、其余后台操作、容器/系统cron/生产运行身份及正式切换时的资源发布。真实商户TLS/结算、SMTP投递与收件箱未在本批执行。整体目标继续进行。
