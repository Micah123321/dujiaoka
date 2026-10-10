# Linux 队列与进程托管验收

本轮使用已有 WSL Debian 13、Linux 6.18.33.2 与普通用户 UID1000建立测试运行时。PHP8.5.11来自Sury Debian13包，与Windows候选补丁版本一致；十个PHP模块包及七个动态库仅解包到项目测试目录，没有执行系统安装、修改软件源或接触店铺环境。Supervisor4.3.0从PyPI公开源码解包，用系统Python3.13在用户态运行。下载均逐个核对公开索引SHA256，见[下载证明](../tests/.runtime/linux-runtime-39/download-proof.json)。

## 实际时区缺陷

[应用配置](../config/app.php)原使用PRC别名。当前Debian的标准时区库有Asia/Shanghai、没有PRC路径；首次真实订单worker虽执行任务，却在Carbon解析默认时区时抛InvalidTimeZoneException并退出1，见[修前报告](../tests/.runtime/queue-lifecycle/run-20261009-213701-1930b3a0f66b23d081418ee7/summary.json)与[worker错误](../tests/.runtime/queue-lifecycle/run-20261009-213701-1930b3a0f66b23d081418ee7/A-worker.stderr)。此前完整Linux诊断在出现同一异常及重复notice后主动停止；该次不是完整通过证据，[诊断输出](../tests/.runtime/linux-runtime-39/full-linux-before-timezone-output.txt)保留。

配置改用标准Asia/Shanghai，保留中国业务UTC+8。[永久回归](../tests/Unit/Runtime/ApplicationTimezoneTest.php)验证标识属于标准列表，并核对2026冬夏偏移都为28800秒；旧根与候选修前各1失败/1断言，[旧根](../tests/.runtime/linux-runtime-39/timezone-before-root.xml)/[候选](../tests/.runtime/linux-runtime-39/timezone-before-candidate.xml)。现有配置缓存仍保留原值，实际部署应按原流程重新生成配置缓存；本轮没有运行店铺cache:clear或config:cache。

## 真实Supervisor托管

[永久夹具](../tests/Integration/LinuxLifecycle/run.php)实际使用Supervisor4.3.0用户态源码。私有unix控制socket位于随机/tmp目录、0700权限；worker采用本次PHP_BINARY与完整INI及原隔离console入口，保留生产tries2/timeout60/startsecs5/stopwaitsecs90、进程组停止和autorestart设置。用户、路径与输出落盘按夹具替换，未运行生产artisan。

[实际报告](../tests/.runtime/queue-lifecycle/run-linux-20261009-214234-6af7b7e0f184e14f37e06e2d/summary.json)两场景通过，774次检查，三次provider响应与一次arraymail、四个JobProcessing/Processed全部txn0。Supervisor原日志记录PID431在途收到SIGTERM后STOPPING，release后exit0；job2保持attempt0/unreserved，start后PID464只消费该任务一次。真queue:restart写filecache使464正常退出，autorestart生成477消费新API/邮件，再shutdown退出0。最终jobs0/failed0、四个outbox全部delivered、密文与lease/token清空，所有ownedworker/ctl/supervisord已消失。完整SQL、源配置与日志逐项[独立核对](../tests/.runtime/queue-lifecycle/run-linux-20261009-214234-6af7b7e0f184e14f37e06e2d/parent-proof-39.json)。

## 强制失联的Linux差异

原通用夹具将默认proc_terminate作为强制失联。Linux实际会发SIGTERM，真实worker设置shouldQuit而继续在途任务，E最终等待超时并以exit0退出；A–D已通过，[修前报告](../tests/.runtime/queue-lifecycle/run-20261009-214002-1aa286f86b2264a226d677b1/summary.json)保留。现[通用夹具](../tests/Integration/QueueLifecycle/run.php)仅在失联场景对ownedproc发SIGKILL、核对signaled与termsig9，保留Windows原行为；清理使用TERM限时5秒，再KILL限时5秒，避免无界proc_close等待。Linux信号退出的exitcode可能为负值，退出证据不再依赖正数255/137。

## 最终三平台回归与原生清理

| 平台 | 完整测试 | 断言 | 结果 |
| --- | ---: | ---: | --- |
| Windows PHP7.4.33 / Laravel6 / PHPUnit9 | 2299 | 18681 | [exit0](../tests/.runtime/phpunit-root-final-39.xml) |
| Windows PHP8.5.11 / Laravel13 / PHPUnit13 | 2321 | 18250 | [strict exit0](../tests/.runtime/laravel12/laravel13-final-39.xml) |
| Linux PHP8.5.11 / Laravel13 / PHPUnit13 | 2321 | 18250 | [strict exit0](../tests/.runtime/linux-runtime-39/phpunit-candidate-linux-final-39.xml)，310.817秒 |

[Linux最终五场景](../tests/.runtime/queue-lifecycle/run-20261009-214403-234703ed02e0444cf5857e3c/summary.json)、[Windows旧根](../tests/.runtime/queue-lifecycle/run-20261009-214432-495ef8de55e0c0197abbf2f9/summary.json)及[Windows候选](../tests/.runtime/queue-lifecycle/run-20261009-214432-ae0dcb6f12b53b976ba85f36/summary.json)各2452checks/24childPIDs，保留四个实际Event子进程和两次tick。LinuxE退出证据为signaled=true/termsig9/exit-1；最终jobs0、旧MaxAttemptsExceeded失败记录1为预期保留，九条outbox全部送达。

独立审计核对首Supervisor成功路径，同时指出失败清理可能在旧identity校验失配后无限proc_close。现共享[进程清理](../tests/Integration/LinuxLifecycle/process.php)直接使用已持有的ownedproc句柄，TERM5秒→KILL5秒，只在确认退出后close；[真实探针](../tests/Integration/LinuxLifecycle/cleanup-probe.php)故意让子进程忽略TERM并失配exec快照，实际5.062秒以SIGKILL回收，见[探针报告](../tests/.runtime/queue-lifecycle/run-cleanup-b61ebbbea81b35bae265cafd/summary.json)。修正后的最终[Supervisor报告](../tests/.runtime/queue-lifecycle/run-linux-20261009-215202-aed9598844469fe605dca1fa/summary.json)777checks、3workerPID256/286/296、4tasks/3API/1mail，全部txn0、shutdown0/jobs0/failed0；[独立最终证明](../tests/.runtime/queue-lifecycle/run-linux-20261009-215202-aed9598844469fe605dca1fa/parent-proof-39.json)核对源文件/生产配置hash当次相等。首774报告保留为修前成功路径证据，最终报告使用修正后的helper。

## 原任务真实60秒PCNTL超时与补偿

[父夹具](../tests/Integration/LinuxLifecycle/timeout.php)和[独立worker入口](../tests/Integration/LinuxLifecycle/timeout-console.php)保持原DeliverNotification timeout60/tries1及真实Worker::kill默认值，CLI刻意timeout1。仅Guzzle合成provider阻塞75秒保护上限，不使用D/E原15秒门；实际JobProcessing到JobTimedOut为60.064623秒，后者timeout60、WorkerStopping reason=TimedOut/status1，worker自己SIGKILL、termsig9/exit-1，父无cleanup signal也无ConsoleExit。原1号job保存TimeoutExceededException及原serializedpayload，队列清空但outbox仍attempt1且未delivered、lease保持，所有事件/连接txn0。

只将本夹具执行lease设为到期后，原notifications:dispatch发布新2号job，新worker实际204一次/attempt2、txn0；最终jobs0/outboxdelivered且lease/token清空，原失败记录完整保留。完整[实际报告](../tests/.runtime/queue-lifecycle/run-timeout-20261009-215500-4b1ea3d691c7a5e91441c126/summary.json)与[独立验证](../tests/.runtime/queue-lifecycle/run-timeout-20261009-215500-4b1ea3d691c7a5e91441c126/parent-proof-39.json)共472checks、四child全部回收。这里60秒为真实墙钟，lease到期仍是明确的夹具时间模拟；没有证明远端已经接受却未成功落库的窗口。

首次native运行已发生60.063558秒真实中断，但Symfony CLI选项为字符串，夹具严格int比较导致FAIL；仅数值归一后最终全程重放通过，原[失败](../tests/.runtime/queue-lifecycle/run-timeout-20261009-215202-57f745d2ba036cead262a1d9/summary.json)/[独立修前元信息](../tests/.runtime/queue-lifecycle/run-timeout-20261009-215202-57f745d2ba036cead262a1d9/parent-before-proof-39.json)保留，未将其作为完整验收。

准确[顺序重放入口](../tests/Integration/LinuxLifecycle/README.md)使用本工作区独立binary/INI/库/源码。

## 验收边界

正式运行使用PCNTL、POSIX和PDO SQLite，并保留E_ALL。[运行时证据](../tests/.runtime/linux-runtime-39/runtime-evidence.json)记录binary/INI哈希、UID、内核、模块与扫描INI。首次探索缺iconv的入口失败与重复openssl加载已分别修正，不归于正式运行结果。

实际Linux[Composer离线平台检查](../tests/.runtime/linux-runtime-39/platform-proof.json)23项全部通过，八份最终PHP源在7.4/E_ALL语法检查无错误。旧根2298/候选2320原身份全部保留，仅新增ApplicationTimezoneTest；Linux2321与Windows候选身份逐项一致，见[覆盖身份核对](../tests/.runtime/linux-runtime-39/discovery-proof.json)。14份[保护文件对照](../tests/.runtime/linux-runtime-39/protected-after.json)中仅应用时区配置变化、另13份含生产托管/cron/任务/依赖清单及锁不变，112/129个包图可[复用已通过证据](../tests/.runtime/linux-runtime-39/package-graphs-proof.json)。

同版候选依赖与生产托管配置保留，测试使用独立SQLite/cache/storage、随机marker和合成provider/arraymail。Windows既有五场景报告证明的是当时平台行为，本轮Linux结果单独归档。WSL运行证据不等于Docker镜像、系统cron、容器application用户、真实SMTP/回调或部署数据库验收；正式根依赖仍待后续切换。
