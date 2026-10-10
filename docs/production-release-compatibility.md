# 独立生产发布目录验收

第44轮针对正式切换前的实际缺口建立[独立发布夹具](../tests/Integration/ProductionRelease/README.md)。当前比较候选autoload仍指向工作区app，后台浏览器夹具则从候选vendor直接提供资源；这些开发验收不自动证明生产发布在无开发依赖、旧public资源和原生优化缓存条件下可运行。本轮在新目录实际复制源码与vendor、安装或复验固定锁的100运行包、排除29开发包，再执行原Artisan及真实Nginx/FPM。

最终采用[新的清洁发布目录](../tests/.runtime/production-release/run-20261010-061533-28a3a30b1f71da255603b294/stage-summary.json)，固定锁安装阶段37checks/305.125秒通过；[vendor复制证明](../tests/.runtime/production-release/run-20261010-061533-28a3a30b1f71da255603b294/vendor-copy-proof.json)记录来源为本轮先前已真实安装的自有no-dev目录，两份所有vendor文件路径与SHA相同，复制后再次由原Composer执行安装和优化autoload。应用源码、DB、storage与框架缓存均从新基线创建，未复制先前运行状态。最终[真实HTTP与CLI终态](../tests/.runtime/production-release/run-20261010-061533-28a3a30b1f71da255603b294/runtime-summary.json)189checks/32网络请求/10动态FPM事件通过，123.683秒；六原PHP命令全部原始shutdown error=null，生产请求从已生成的config/routes缓存运行，App/vendor均来源于独立release。32客户端与Nginx32记录按唯一ID/method/URI/status严格对应，10动态请求另与FPM逐项对应。

[独立复读证明](../tests/.runtime/production-release/run-20261010-061533-28a3a30b1f71da255603b294/parent-proof-44.json)6709checks通过：重开100包精确version/source和dist reference、29开发包缺席、同锁、原源码快照、850已发布资源、32客户端/Nginx32/FPM10真实记录及响应原字节、六CLI原始报告、合成SQL、根保护hash和原身份/双监听关闭。vendor真实复制共12623文件。首次独立proof把生成时空合成SQLite与seed后的DB按不可变源码比较失败；实读唯一非资产变化为该文件，随后精确检查空初始hash与seed后原SQL记录，全部源码保持。

第46轮新增[独立发布真实交易链](production-transaction-compatibility.md)：相同100运行包的新空发布保留32原只读HTTP后再跑24交易请求，472checks/56client/34FPM及父独立24503checks通过；原worker提前消费、coupon单次补偿双sweep、原订单Cookie密码校验、UUID失败记录log/forget均有实物证据。第44证据仍保留；本页原只读报告范围不被后续交易包替代。

## 发布输入与原生安装

[stage入口](../tests/Integration/ProductionRelease/stage.php)复制当前app/bootstrap/config/database/resources/routes/public/deploy/artisan，排除真实环境、源uploads/storage链接、tests、旧vendor与生成bootstrap缓存。源码和vendor均为真实文件，不使用指回工作区的链接。候选清单仅将App PSR4和helper改为release根相对路径，去除autoload-dev；require/require-dev及锁内容保持。

原Composer在本次专用home/cache下执行：

```text
validate --no-check-publish --no-plugins --no-interaction
install --no-dev --prefer-dist --no-scripts --no-plugins --no-interaction --optimize-autoloader
check-platform-reqs --no-dev --no-plugins --no-interaction
```

[100运行包精确清单](../tests/.runtime/production-release/run-20261010-061533-28a3a30b1f71da255603b294/installed-proof.json)与原候选lock一致，installed dev=false，全部29开发包缺席。根112/候选129的工作区锁图保持；发布只安装候选100运行包，锁仍包含29开发包记录，未以删除记录来伪造无dev安装。

[setup-only seed](../tests/Integration/ProductionRelease/seed.php)导入合成原schema/管理员/商品/订单，使用本release vendor；它检查真实App/vendor反射路径、生产环境、SQLite地址、Loader全部PSR/classmap路径及无外部fallback，Tests/PHPUnit/Mockery/Faker加载失败。该测试数据helper不进入发布目录，也不出现在生产请求加载文件中。

## 资源发布与优化缓存

[运行入口](../tests/Integration/ProductionRelease/run.php)实际执行原`package:discover`、`admin:publish --assets --force`、`config:cache`、`route:cache`、`view:cache`。限定assets参数避免覆盖应用配置或追加组件迁移。已观察原工作区旧公开资源与安装Dcat X2.0.4 dist字节不同；新发布在本次owned目录清掉旧组件资产后重新发布，完整路径集合与SHA必须等于已安装dist。工作区public资源不变。

生成配置、路由、包/服务发现和编译视图缓存后才开启本次FPM/Nginx。生产请求使用原public/index.php、原AppKernel、原Dcat登录/网格/退出，未替换成功响应或用测试router提供资源；Nginx从本release public实际提供Vue和后台JS/CSS，逐请求核对字节与MIME。

[CLI观察器](../tests/Integration/ProductionRelease/cli-prepend.php)保存每个原PHP命令shutdown时原始error_get_last，[同一命令执行器](../tests/Integration/ProductionRelease/command.php)同时核对退出0、stdout/stderr PHP诊断和原始报告；仅exit0不足以标成功。发布源码/配置/锁的前后hash和实际DB终态另独立核对。

## 失败控制与共享进程修复

原Composer安装成功后，早期夹具调用了错误清理函数名，外层exit1且缺stage-summary：[真实失败归档](../tests/.runtime/release44/initial-stage-launch.json)保留。根据原始日志及installed-proof确认三Composer子命令0后，从本次标记目录恢复，不重复下载；恢复标签唯一、旧失败摘要保留。后续恢复期间短命Composer后代使原session追踪误报namespace；共享[追踪器](../tests/Integration/ScheduleWorkerLifecycle/process.php)增加zombie排除和读namespace后的PID/start_ticks存活重查，仍保留活动进程UID/SID/PGID/namespace核对。

[真实未回收zombie控制](../tests/Integration/ProductionRelease/process-probe.php)修前在“zombie不应作为活动owned身份”断言失败，修后保持zombie存在而正确追踪活parent、原reap/exit0通过；该控制与实际短命进程namespace竞态分别记录。原晚生、失父、抵抗TERM派生控制重新通过，5.304秒清理，详见[本轮实际报告](../tests/.runtime/queue-lifecycle/run-cleanup-20261010-061106-a2813c4b110bc3f703c74a7f/summary.json)。

限定审计指出的三项验收缺口已补：

- 删除/发布资产前检查目标及每层祖先真实路径一致且非链接；四类祖先真实symlink控制下，外侧本次合成sentinel字节保持。
- 恢复资格在进入写入流程前检查；对未进入runtime的PASS stage实际`--resume-install`返回1，摘要SHA保持，见[保护证据](../tests/.runtime/release44/pass-stage-resume-proof.json)。
- 实际CLI E_USER_DEPRECATED仍退出0时，原命令执行器判失败并保留原始问题；四路径与该控制合计[13checks通过](../tests/.runtime/production-release/run-controls-20261010-061808-400b23165570bb1bc432693a/controls-summary.json)。

[实际启动后故障控制](../tests/Integration/ProductionRelease/failure-probe.php)借用最终发布的只读应用/public配置，另建专属pid/log/临时目录和两个新端口；原FPM/Nginx masters与workers已实际就绪后抛出准确故障，外层exit1按EXPECTED_FAILURE记录。四owned身份有界回收、两根句柄闭、两监听关闭，主发布DB及成功流量/摘要字节保持；[独立复核](../tests/.runtime/release44/fault-20261010-062948-278354f67a0f2c42a01edc14/parent-proof.json)14checks通过。该控制不再次发送业务请求，不冒充重新完成32请求链。

## 实际范围与重放

使用[夹具说明](../tests/Integration/ProductionRelease/README.md)中的当前Linux8.5.11/独立INI命令。首次空vendor真实下载/安装100包的原日志与失败收尾都保留；`--from-installed RUN_PATH`只接受本项目自有、已成功且同锁的发布vendor，生成全新release并比较所有字节，原Composer仍重新验证和生成优化autoload。

[真实FPM观察器](../tests/Integration/ProductionRelease/prepend.php)逐请求保存真实App/vendor源、included文件、唯一本release Composer loader、production环境及配置/路由已缓存标志。除三个明确列出的纯观察helper，PHP应用文件应全部位于本release，开发类保持未加载。这是实际加载路径证据；工作区对观察进程仍可见，不宣称OS文件系统隐藏或完整容器隔离。

本轮是普通UID1000私有发布目录与真实SAPI，不替代OCI构建、Docker Engine停止计时、stock镜像root→application切换、系统cron、实际部署数据库/上传挂载和真实商户SMTP。原Dockerfile仍使用7.4/根旧锁，正式切换与发布目标继续待各自验收；整体目标保持active。
