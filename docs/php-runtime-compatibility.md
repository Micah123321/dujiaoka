# 第三十三轮：PHP 8.5 与 PHPUnit 13 运行兼容验收（历史）

本页记录第33轮实际使用 PHP8.5.11 / Laravel12.69.3 / Dcat X1.2.2 / PHPUnit13.4.1 的证据；第34轮当前候选已升级Laravel13/Dcat X2，重放入口与最新证据见[维护期框架验收](laravel13-compatibility.md)。当轮全部常规回归、六项SMTP和四项真实表格协议合计2128项/16529断言，在严格模式下通过。根PHP7.4.33 / Laravel6.20.42 / PHPUnit9.6.38完整2118项/16948断言通过；根框架和部署配置继续保留。

## 维护周期与版本边界

按2026-10-10查询的官方资料：

| 组件 | 官方维护期限 | 本次验收范围 |
| --- | --- | --- |
| PHP8.5 | 活跃维护至2027-12-31，安全修复至2029-12-31 | Windows NTS VS17 x64 8.5.11实际执行 |
| PHP8.4 | 活跃维护至2026-12-31，安全修复至2028-12-31 | 仅作为候选声明的最低版本，未实测 |
| PHPUnit13 | PHP>=8.4，常规bugfix至2028-02-04 | 实际13.4.1，严格问题失败 |
| Laravel12 | bugfix至2026-08-13，安全修复至2027-02-24 | 第33轮验证12.69.3；当前候选已转Laravel13 |

来源：[PHP支持版本](https://www.php.net/supported-versions.php)、[PHPUnit支持版本](https://phpunit.de/supported-versions.html)、[Laravel12发布与支持政策](https://laravel.com/docs/12.x/releases)。PHP与测试运行器已获得本地维护版验收；Laravel12当前处于安全修复阶段，整体框架迁移目标继续进行。

[候选清单](../tests/Compatibility/Laravel12/composer.json)最低PHP约束提高为^8.4，测试工具为^13.0。此前PHP8.2/PHPUnit11属于[第32轮比较台阶](phpunit-compatibility.md)，现有候选锁已取代该组合；系统PHP和根依赖没有在本批升级。

## 官方运行时与最小依赖变化

便携运行时来自[PHP Windows官方元数据](https://downloads.php.net/~windows/releases/releases.json)与[8.5.11官方压缩包](https://downloads.php.net/~windows/releases/php-8.5.11-nts-Win32-vs17-x64.zip)。下载36048897字节，解压前实际SHA256验证：

```text
0ea96e0d2b9b737a6036f05cf4e95c49313faa6d0f27bd97edb2742503f0c043
```

压缩包及便携目录均在忽略的tests/.runtime内；独立[运行INI](../tests/.runtime/php85-fixture.ini)加载该目录ext中的mbstring、pdo_sqlite、pdo_mysql、openssl、fileinfo、gd、curl和zip，启用E_ALL及断言，时区Asia/Shanghai，不改系统PHP配置。INI中的extension_dir是此工作区绝对路径，异地重放须指向相应便携目录。

初次PHP8.5平台检查实测发现OpenSpout4.28.5只允许8.2/8.3/8.4。按安装树Dcat X → easy-excel1.0.2 → OpenSpout^4.0链，最小更新至4.32.0，其声明支持8.3/8.4/8.5；没有忽略平台要求。先dry-run后实际执行：

```powershell
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini E:/php/composer.phar update phpunit/phpunit openspout/openspout --with-all-dependencies --minimal-changes --no-scripts --no-plugins --no-interaction --working-dir=tests/Compatibility/Laravel12
```

最终131包（102运行/29开发）；总2新增、21更新、2移除。运行包完整元数据逐项与[更新前基线](../tests/.runtime/phpunit11-candidate-baseline-33.json)比较，仅OpenSpout变化，另101包名称/版本及全部锁元数据相同。开发工具链20更新/2新增/2移除；Faker和Mockery不变。见[完整对照](../tests/.runtime/laravel12/php85-dependency-diff-33.json)。Laravel、Dcat、支付SDK、邮件和数据库依赖均保留已验收版本。

## 实际问题与修复

首次PHP8.5/PHPUnit11完整运行发现NAN字符串转换警告、隐式nullable和旧PDO常量弃用，strict exit1；原平台检查失败，因此这次探索结果不作为验收通过。

- [金额校验](../app/Payments/CurrencyAmount.php)在字符串转换前拒绝非有限float；原NAN/非法金额测试继续抛同一InvalidArgumentException，避免PHP8.5先发警告。
- [AppException](../app/Exceptions/AppException.php)、[RuleValidationException](../app/Exceptions/RuleValidationException.php)、两种[恢复](../app/Admin/Actions/Post/Restore.php)/[批量恢复](../app/Admin/Actions/Post/BatchRestore.php)构造及两测试helper显式nullable；异常默认code仍为0/400，model默认仍为null。
- [SalesCard](../app/Admin/Charts/SalesCard.php)去除位于必需时间参数之前的style默认值；实际唯一调用仍传五个参数。
- [MySQL配置](../config/database.php)在新版常量存在时通过defined/constant使用Pdo\Mysql::ATTR_SSL_CA，旧PHP选择原PDO常量；保留原SSL选项值和array_filter，避免8.5弃用。
- [候选XML](../tests/Compatibility/Laravel12/phpunit-core.xml)用recordTestRunHistory=false替换13已弃用的cacheResult，保留failOnAllIssues及displayDetailsOnAllIssues。新版真实运行还指出[Coinbase测试](../tests/Unit/Payments/CoinbaseCommerceClientTest.php)无expects的with与无期望mock：首个改为once/getConfig(handler)并保留never send，纯异常替身改createStub；未使用告警抑制。

中间nullable签名解析失败已定位修复，修复后的两PHP语法与完整回归通过。当时扫描的229份应用、配置、普通测试及候选顶层入口PHP8.5编译无告警；本批11份变更文件PHP7.4语法通过。独立审查未发现可复现P1/P2，范围为静态语义和完整包元数据，未代替运行验收。

## 验证证据

| 实际运行 | 测试 | 断言 | 结果 |
| --- | ---: | ---: | --- |
| PHP7.4 / Laravel6 / PHPUnit9完整 | 2118 | 16948 | exit0，17.025秒 |
| PHP8.5 / Laravel12 / PHPUnit13原核心 | 2124 | 16493 | strict exit0，23.587秒 |
| PHP8.5 / Laravel12 / PHPUnit13最终完整 | 2128 | 16529 | strict exit0，22.566秒 |
| 真实easy-excel CSV/XLSX | 4 | 36 | 已纳入最终完整套件 |

JUnit：[根](../tests/.runtime/phpunit9-root-final-33.xml)、[原核心](../tests/.runtime/laravel12/php85-phpunit13-core-final-33.xml)、[最终候选](../tests/.runtime/laravel12/php85-phpunit13-final-33.xml)。候选无warning、PHP/PHPUnit弃用、notice、risky、skip、incomplete；Injected queue/notification failure日志来自既有异常恢复测试，CLI exit0。单独E_USER_DEPRECATED负对照在13仍返回exit1并出现精确sentinel，普通suite不含该负对照。未采集coverage。

PHPUnit11与13的[原核心逐项发现清单](../tests/.runtime/laravel12/phpunit13-discovery-core-33.json)均2124，无缺失/新增/重复；[最终对照](../tests/.runtime/laravel12/phpunit13-discovery-final-33.json)2124→2128，仅新增四项表格案例，原用例无缺失/重复。

[真实表格测试](../tests/Compatibility/Laravel12/SpreadsheetCompatibilityTest.php)使用Excel::export()->type()->store()及Excel::import()->toArray()，实际写文件/读回；覆盖中文、引号逗号换行、字符串0、日期、保留前导零和小数位的金额、headerless generator。CSV将数字/布尔读回字符串，XLSX按单元格类型读取；另解析实际worksheet XML核对字符串单元格及ZIP内容。物理行号和格式差异均按实际库契约断言，临时文件在专用随机子目录创建并finally清理。

真实PHP8.5应用probe报告158路由、SQLite :memory:、独立storage、ArrayTransport。PHP8.5后台浏览器从干净隔离夹具完成43页面检查/8统计请求/56资源，真实认证、CSRF、分类/商品/文本导入/设置保存及移动导航通过，见[固定本轮报告](../tests/.runtime/admin-candidate/browser-php85-final-33.json)与[后台范围](admin-compatibility.md)。未伪造本地成功响应；临时8198服务已关闭。第30轮报告仍保留PHP7.4/8.2阶段边界；本轮另实际以PHP8.5.11重跑MySQL8.4.11/RR候选，36组/85worker及七份前向迁移通过，十五表全InnoDB。36份锁报告均验证释放前及继续500ms后全部worker处于LOCK WAIT，150ms观察间隔不变，见[新实际summary](../tests/.runtime/mysql-concurrency/run-20261010-023122-33d558eb/summary.json)及[锁证据核对](../tests/.runtime/laravel12/php85-mysql-proof-33.json)。实例已正常shutdown并确认13316无监听。

Composer validate/check-platform-reqs通过，原QRCode2.0.0精确约束提示保留，候选安装报告无公告；根旧依赖公告仍需框架切换处理。

## 重放与后续边界

第33轮锁已由第34轮Laravel13候选取代；原JUnit、浏览器及MySQL固定报告保留上文版本范围。当前重放请使用[Laravel13验收文档](laravel13-compatibility.md)的命令，避免将当前运行结果覆盖到旧阶段报告。

后台重放使用[后台文档](admin-compatibility.md)的当前PHP8.5命令及node浏览器入口。旧根比较使用独立PHP7.4与mysql-fixture-php.ini。便携版本/INI不是生产FPM、扩展镜像、队列服务或计划任务配置。第42轮另以同补丁实际FPM与Nginx完成[17请求/145checks及176项独立复核](http-runtime-compatibility.md)，仅闭合私有Linux SAPI，不替代生产镜像和身份切换验收。

第43轮[跨版本文件状态](cross-version-state-compatibility.md)与真实组件弃用修复后，完整Windows旧根2301/18693、Windows及Linux候选2323/18262再次严格通过。原后台权限/批量/上传/状态邮件及候选原生worker/分钟调度分别保留已有范围；实际部署数据库/结构、完整镜像/systemcron/stock身份切换和正式根依赖切换继续验收。真实商户/TLS/SMTP与正式资源发布未执行，整体目标保持进行。
