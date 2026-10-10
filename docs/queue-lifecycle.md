# 真实队列进程与计划任务生命周期

第35轮已复现并修复现代API回调队列退避损失；PHP7.4.33/Laravel6.20.42与PHP8.5.11/Laravel13.35.0各完成五类真实独立PHP进程生命周期场景，均exit0。每个平台24个子进程、两次schedule tick/四个Event子进程以及18次JobProcessing都逐项核对。完整根2119/16963、候选2141/16588也已重跑通过。

## 30秒退避兼容缺陷

[ApiHook任务](../app/Jobs/ApiHook.php)原本仅有Laravel6识别的retryAfter=30。现代框架创建新队列payload使用backoff入口；缺失时真实数据库worker处理503后available_at等于1767240000，期望为1767240030。这个故障已在[修复前JUnit](../tests/.runtime/laravel12/webhook-retry-before-35.xml)永久保留，exit1 / 1测试 / 6断言 / 1失败。

同一用例在旧根PHP7.4.33/Laravel6.20.42通过1测试/15断言，见[旧根修复前对照](../tests/.runtime/webhook-retry-root-before-35.xml)。兼容修复仅新增backoff():int返回已有retryAfter，保持一个延迟来源，不改变旧属性、发送字段或默认outbox开关。候选ApiHook4测试/26断言通过，见[修复后聚焦](../tests/.runtime/laravel12/webhook-retry-after-35.xml)。

[永久回归](../tests/Feature/ApiHookTest.php)建隔离SQLite orders/jobs/failed_jobs，存真实订单并通过Dispatcher生成模型标识队列payload，再调用原Kernel queue:work --once、tries1，经原WorkCommand/Worker消费。Guzzle边界提供503/204；逐项断言第一次只有一个请求、队列attempts1/reserved_at null/available_at+30/failed_jobs0，第二次到期前仍只有一请求，仅修改本夹具available_at模拟到期后第二次成功/jobs0/failed0。任务两次上限优先于worker tries1得到实证。这是原worker的进程内入口，独立PHP子进程由下面单独验收。

独立只读审查未发现本轮P1/P2或通过假象。现代Job.backoff()仍有旧payload delay字段兼容读取，新增方法没有序列化字段；源码核实根已排队的delay=30仍可读。兼容入口对新生成payload生效，修前已经生成backoff=null且没有delay的现代payload仍沿用worker默认值，本轮没有回写这些记录；跨框架旧队列完整消费尚未实测。普通回归使用固定Carbon时钟，未等待真实30秒墙钟时间。

## 两套完整回归

| 运行平台 | 实际完整结果 | 证据 |
| --- | --- | --- |
| PHP7.4.33 / Laravel6.20.42 / PHPUnit9.6.38 | 2119测试 / 16963断言 / exit0 / 15.998秒 | [根JUnit](../tests/.runtime/phpunit-root-final-35.xml) |
| PHP8.5.11 / Laravel13.35.0 / PHPUnit13.4.1 strict | 2141测试 / 16588断言 / exit0 / 22.975秒 | [候选JUnit](../tests/.runtime/laravel12/laravel13-final-35.xml) |

原2118/2140测试分别保持，本轮共享新增一个原数据库worker回归。无失败/错误/跳过；严格候选无warning/deprecation/notice/risky/incomplete。故障注入的队列/通知stderr日志保留原意义；未采集coverage。第34轮[维护期候选](laravel13-compatibility.md)与[后台](admin-compatibility.md)/[MySQL竞争](mysql-concurrency.md)记录继续保留当时版本和报告，本轮未重跑浏览器或MySQL。

## 独立进程五场景验收

[永久夹具](../tests/Integration/QueueLifecycle/README.md)使用隔离SQLite文件的真实持久database queue，父进程入库与独立PHP console worker消费，实际App ConsoleKernel schedule:run与其真实Event子进程。每轮仅新随机marker目录，独立DB/cache/storage/config/services/routes/packages/events与不存在环境文件，拒绝路径漂移；每个进程记录PHP_BINARY/完整INI/framework源文件/连接/环境与PID，输出只保留合成数据及任务状态元信息。

默认Event命令会在base_path启动根artisan，仅隔离父进程配置会失去隔离。因此夹具保留原AppKernel events、cron及withoutOverlapping，只把每个已确认任务命令入口改为同PHP、完整INI及隔离console入口；由实际Scheduler/原业务命令运行，不改生产调度注册。

| 场景 | 实际行为与状态验收 |
| --- | --- |
| A：序列化与过期 | 原OrderExpired经真实数据库payload与独立worker反序列化消费，重复只退一次券，future deadline不失效 |
| B：发布与通知 | 付款状态与API/加密邮件意图在真实事务落库，publisher只入queue，外层DeliverNotification由worker处理；重复token一次，503进入outbox退避而queue ack，补发仅恢复API，邮件成功清空密文 |
| C：真实双次调度 | 原Kernel注册的orders:expire/notifications:dispatch由真实schedule:run及四个Event子进程执行，孤儿过期退券/意图入queue，future/paid保留，二次tick无新增通知；mutex.exists断言四次执行前持有与执行后释放 |
| D：缓存重启信号 | provider门控制当前任务，另一PHP执行真queue:restart/filecache时间戳；旧worker完成当前任务后exit0，下一任务留待新worker消费 |
| E：实际失联恢复 | 仅终止本轮owned worker，exit255，持久reserved job与outbox轮换lease保留；显式改变夹具时间戳模拟到期，原lost任务达tries1形成一个MaxAttemptsExceeded failed记录，old token副本no-op，重新发布单次完成 |

两树均有2452条原始检查，其中包含每个进程重复核对环境与源码。最终jobs0、9条outbox全部delivered且无failed、三封array邮件快照清空；每树7次合成provider响应/3次array接受。18次JobProcessing全部transactionLevel0，所有正常子进程exit0并关闭数据库，唯一主动失联子进程exit255。Windows路径分隔符统一后，父逐条核对事件与每个Start配置，见独立proof。最终独立审查指出E仅看总次数缺少新旧job归属，已增加JobProcessing/Processed实际job ID关联与ProviderResponse.job_id断言；父再逐条确认唯一E响应位于新job17 processing/processed区间，旧copy16无作用。加强后两平台五场景均再次完整通过。

正式报告：[旧根summary](../tests/.runtime/queue-lifecycle/run-20261009-192903-bf00b4d91f1adabfe4f2c9c7/summary.json)、[候选summary](../tests/.runtime/queue-lifecycle/run-20261009-192903-844c741146f171f2278e9163/summary.json)；[旧根独立核对](../tests/.runtime/queue-lifecycle/run-20261009-192903-bf00b4d91f1adabfe4f2c9c7/parent-proof-35.json)、[候选独立核对](../tests/.runtime/queue-lifecycle/run-20261009-192903-844c741146f171f2278e9163/parent-proof-35.json)。程序按scenario最终行数、作用次数及所有process状态验证，CLI exit0单独不作验收依据。

子代理最初使用系统8.2.31对Laravel6探索并屏蔽弃用；父移除所有屏蔽并加入正式旧根7.4/candidate>=8.4版本守卫，然后上述两树在E_ALL下完整实际重放。早期8.2 exploratory文件保留原标记，不归于7.4验收。六份根依赖/原artisan/原bootstrap/ConsoleKernel/queue配置SHA256[与本轮前相同](../tests/.runtime/queue-lifecycle-protected-35.json)，生产调度配置未改。

当前正式根已升级现代框架；历史重放中的root标签明确选择`tests/Compatibility/Laravel6/vendor`，候选仍选择`tests/Compatibility/Laravel12/vendor`，实际包发现也绑定各自vendor。先执行`composer install --working-dir=tests/Compatibility/Laravel6 --no-scripts --no-plugins`；Composer2.10用完整PHP8.5夹具INI执行，清单固定platform.php=7.4.33。两个夹具均强制MANUAL_STATUS_MAIL_OUTBOX=false。重放从工作区根执行，子进程继承PHP_BINARY及php_ini_loaded_file对应完整INI：

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini tests/Integration/QueueLifecycle/run.php
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Integration/QueueLifecycle/run.php --candidate
```

每次新随机runtime，旧summary不覆盖。--through=C仅选前三场景，正式报告均跑A–E；输出只在本次runtime并保留报告，正常/异常finally关闭或终止本轮owned子进程。503退避/租约/retry_after到期只改变夹具明确字段，未实等60/90/300秒；终止发生在合成provider返回前，不证明远端已接受后丢失成功落库的情况。

第35轮Windows解释器没有Linux PCNTL语义，该轮timeout参数与父有限等待不构成原生超时实证。第39轮同版Linux候选已完成A–E、真实用户态Supervisor停止/排空/自动重启与有界清理，并保留[独立Linux证据](linux-worker-compatibility.md)；E对owned句柄显式SIGKILL、记录termsig9/exit-1，Windows原行为保留。容器、系统cron、真实回调/SMTP和生产运行身份另行验收，正式根依赖切换仍待部署版本/schema与资源发布。
