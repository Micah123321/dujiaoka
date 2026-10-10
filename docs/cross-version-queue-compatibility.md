# 原队列与失败记录的双框架迁移

第48轮新[原生队列夹具](../tests/Integration/CrossVersionQueue/README.md)通过302项检查、26个独立原App Console Kernel进程；[独立复核](../tests/.runtime/cross-version-queue/run-20261010-081120-6c5c26453b3a86f58a537cd7/parent-proof-48.json)18229 checks直接重开原payload、事件、SQL快照和最终SQLite。逐文件、逐加载来源的检查数不表示不同业务场景数量。

## 旧载荷和旧失败ID

两树为Windows PHP7.4.33/Laravel6.20.42与PHP8.5.11/Laravel13.35.0，共享新marker私有SQLite，保留database失败驱动和原整数ID表字段。各自storage/cache/log/bootstrap缓存独立，原.env不读取，App和各树唯一Composer loader现场核对。业务表由AdminData按安装SQL字段创建，failed_jobs只有id/connection/queue/payload/exception/failed_at，没有uuid列。

旧原Dispatcher生成App OrderExpired载荷，没有uuid；新原载荷包含uuid。两个方向均消费原序列化私有字段，不手工拼任务JSON。旧worker选项为--delay=0，新worker为--backoff=0；两版本CLI --tries=1均由原任务maxTries=3覆盖。

[旧payload](../tests/.runtime/cross-version-queue/run-20261010-081120-6c5c26453b3a86f58a537cd7/payload-CQ48-OLD-FAIL.json)由Laravel6生成、候选消费；[新payload](../tests/.runtime/cross-version-queue/run-20261010-081120-6c5c26453b3a86f58a537cd7/payload-CQ48-NEW-FAIL.json)由候选生成、Laravel6消费。两方向在实际coupon AFTER UPDATE触发器下执行三次：原JobExceptionOccurred含故障标记，所有业务表完整回滚、事件transaction_level=0；前两次release，第三次唯一JobFailed写整数失败ID。撤触发器后，候选原queue:retry按两个实际ID读取/删除原失败记录、attempts归零且raw payload字节未变，worker恢复过期并仅退券一次。最终jobs和failed_jobs为空。

项目默认[失败驱动](../config/queue.php)是database，原整数ID可跨版本查询和重试。候选DatabaseUuidFailedJobProvider直接读取旧payload不存在的uuid，独立log负控制得到原诊断且没有改变failed行。没有实际运行误配UUID驱动下的三次worker耗尽，故未将其称作完整丢日志链实测。仅补uuid列或切driver都不足以迁移数字失败ID；正式切换需同步处理既有载荷、失败记录和查询标识。

## 原任务期限、幂等与保护

旧原任务设真实15秒未来期限：候选在期限前确认，没有过期或退券；原pushRaw逐字节重放同载荷，实际到期后消费并退券。后续旧worker重复消费确认完成，所有业务列保持。失败恢复只允许目标订单status/coupon_ret_back/updated_at与coupon.ret/updated_at变化，新增SN集合严格为3项。原两seed订单、原券与其他全表完整保留。[运行总结](../tests/.runtime/cross-version-queue/run-20261010-081120-6c5c26453b3a86f58a537cd7/summary.json)保留PID/参数/退出码和原stdout、stderr、shutdown报告。

实际等待15秒，没有声称等待生产5分钟，也没有将当前构造器null期限当作历史序列化缺成员证据。原orders:expire无dev生产补偿实测见[第46轮](production-transaction-compatibility.md)。开发树/SQLite队列结果不等同于无dev、部署MySQL或容器验收。

## PHP8.5启动期验证码适配

在原HandleExceptions bootstrap后立即恢复严格诊断观察，复现[候选验证码Provider](../tests/Compatibility/Laravel12/vendor/mews/captcha/src/CaptchaServiceProvider.php)第40行(double)的PHP8.5弃用；官方[当前源码](https://github.com/mewebstudio/captcha/blob/master/src/CaptchaServiceProvider.php)与[稳定包3.5.1](https://packagist.org/packages/mews/captcha)仍保留该写法。[失败引导](../tests/.runtime/cross-version-queue/run-20261010-075825-46bf6a00db240195b495b43c/tasks/003-candidate-console.json.result.json)保留原位置。此前套件通过不证明此启动阶段没有诊断。

[应用引导](../bootstrap/app.php)仅在PHP8.5及Laravel13加载[原Mews类名的薄继承类](../bootstrap/compat/captcha-service-provider.php)，继承[应用侧Provider](../app/Providers/CanonicalCaptchaServiceProvider.php)，保留配置合并、ImageManager3 driver、原web路由及两验证规则。vendor和两Composer锁未修改，旧根仍加载原Provider。最初alias造成框架按适配类名登记/发布，已修为原FQN声明；[永久回归](../tests/Feature/CaptchaImageTest.php)覆盖原FQN查询、注册去重、发布映射、真实120x36图片，正确/错误/已消费的会话与API校验。

最终Windows完整[旧根](../tests/.runtime/queue48/root-identity-final.xml)2309 tests/18741 assertions、[候选](../tests/.runtime/queue48/candidate-identity-final.xml)2331/18310通过，永久新增4例/13断言。Linux[验证码聚焦](../tests/.runtime/queue48/linux-captcha-identity-final.xml)5/18通过；Linux完整保留第43轮2323/18262范围，没有宣称完整2331已重跑。启动适配后的[新no-dev发布](../tests/.runtime/production-release/run-20261010-081123-a7e2e60f28ea504dd00aed45/runtime-summary.json)已通过491checks/57网络请求/35FPM，原Provider冷/热缓存各13checks、原vendor:publish与实际图片、三单交易/原worker/双expire均通过；[独立父复核](../tests/.runtime/production-release/run-20261010-081123-a7e2e60f28ea504dd00aed45/parent-proof-48.json)27209checks。该491档案保留原raw PHP诊断观察范围；补充的启动后观察器另用最终新基线验证，不将旧结果追认为新增观察已实跑。

[启动后正负控制](../tests/Integration/ProductionRelease/postboot-controls.php)在独立no-dev四个CLI子进程通过[38checks](../tests/.runtime/production-release/run-controls-postboot-20261010-083017-f51038b18671fbcfaec927ff/controls-summary.json)：两原Console/HTTP Kernel正常引导均通过；另两已引导后E_USER_DEPRECATED由原框架接管、child仍0而原验收器拦下。实际完成标记防异常输出/exit0伪通过，独立storage/cache/SQLite和10份原成功release字节保持。它是CLI执行HTTP Kernel引导，不把该负控制称为额外FPM网络请求。外部观察器在原Composer初始化后、原HandleExceptions接管后安装观察并保留原处理行为，CLI/FPM事件保存捕获诊断。

[当前源码的镜像输入](../tests/.runtime/modern-image/run-20261010-082024-a00d2575ff346333c36cb60e/input-proof.json)已由上述491接受发布另行生成，3832checks/1273文件/850资产通过，包含两新Provider文件和原现代Docker配方，固定100运行包锁。第47目录保留历史原字节；新context仍只是INPUTS_VERIFIED，未构建或运行镜像。

[最终新观察发布](../tests/.runtime/production-release/run-20261010-082820-a36a5851a8d5906f05d9df0e/runtime-summary.json)随后通过543checks/57网络请求/35FPM；[严格独立父证明](../tests/.runtime/production-release/run-20261010-082820-a36a5851a8d5906f05d9df0e/parent-proof-48-framework.json)27252checks以--framework-observer强制全部35FPM和六关键CLI字段，缺字段即失败，旧491原报告没有被覆盖。对旧491执行同模式实际wrapper退出1，未写强制证明。第一App原处理器在afterHandleExceptions后捕获诊断，实际35FPM均installed/无捕获issue；此543档案尚未含后述二次App适配，保留这个观察范围。

[二次App终稿正负控制](../tests/.runtime/production-release/run-controls-postboot-20261010-084605-934f311f588cd2a54a98ef33/controls-summary.json)58checks/7原no-dev CLI child0：原Console/HTTP正负、原config:cache基线及观察版正负。原config:cache确实另建App并重置handler，外部观察适配严格校对原getFreshConfiguration方法体，仅附新App回调，继承原handle/序列化方法；两真实App均被记录、第二App故意弃用由原框架接管且child0却被validator拦下。[独立原字节复核](../tests/.runtime/queue48/config-fresh-proof-48.json)确认原基线与观察正控制配置缓存SHA均d1e87221486610af56aebde208459b76b9b6754a500adb150f61f0993319d6f1，并保10份成功release源码/cache/SQL/流量字节不动；原App/vendor/两锁均未作该观察改动，不把控制称作额外FPM。

新stage父跟踪曾实际读取空/短procstat而报三个undefined key warning，37stage子命令校验本身通过；原输出保留。随后仅跟踪harness在完整字段校验后才生成身份，五空/短反例在throwing PHP handler下返回null、实际live root/未reap zombie身份和回收由[原进程探针](../tests/.runtime/production-release-process/run-20261010-083524-b3c4358dabf1bbb24aa39975/summary.json)通过。该独立修复不追认旧stage父输出为空。

## 本地重放

工作区根运行：

~~~powershell
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Integration/CrossVersionQueue/run.php
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Integration/CrossVersionQueue/verify.php RUN_PATH
~~~

RUN_PATH取第一条命令打印的新run。已结束run在进入App前停止再次写入；任务须为tasks的canonical直属文件。子进程35秒上限与owned finally收尾。早期开发失败与通过档案分别保留，不覆盖原SQLite和payload。整体容器、系统cron、真实部署和根切换继续待验。
