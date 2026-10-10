# 真实分钟调度与系统cron验收

候选环境的[分钟调度入口](../tests/Integration/ScheduleWorkerLifecycle/README.md)运行现有Laravel13.35.0的原生 `schedule:work` 循环，PHP8.5.11、普通Host UID/GID1000。它保留原AppKernel的 `orders:expire` 和 `notifications:dispatch` 事件、前台子进程与文件缓存互斥锁；仅把命令路径指向隔离console。正式部署继续使用原crontab的 `schedule:run`，本验收没有切换部署调度方式。

[最终运行](../tests/.runtime/queue-lifecycle/run-minute-20261010-050452-9dabb639023706d5182b3407/summary.json)通过854项检查，两次入口相隔59.748秒，运行155.609秒；daemon与worker均正常返回0，句柄和存活后代为0。父代理[独立核验](../tests/.runtime/queue-lifecycle/run-minute-20261010-050452-9dabb639023706d5182b3407/parent-proof-41.json)155项通过：重开SQLite、重算原事件来源与14类源码哈希，并核对15份已追踪进程身份全部回收。

## 验收输入与行为

SQLite中包含十分钟前的待付订单、关联优惠券、一张未来订单，以及真实事务提交的API回调和付款邮件两条通知意图。首分钟须只过期旧订单、返券一次，发布两条未领取队列任务，通知租约为300秒。第二次实际分钟触发前保持worker停止，验收两次SQL快照完全相同，涵盖队列ID、payload、token和租约。之后原worker排空通知：合成provider一次204、array邮件一次，均在事务级别0、attempt1；最终队列和failed_jobs为0，通知租约、token和邮件payload清空。

应用没有冻结Carbon，也没有从父进程人工调用 `schedule:run` 来代替分钟循环。两个入口记录须来自原生worker派生的子进程，处于相邻墙钟分钟，时间差约60秒。原始日志、首次/次次SQL快照、最终SQLite及所有进程身份保存在各次运行目录。

## 信号与异常清理

原ArtisanServiceProvider在 `testing` 环境关闭命令信号处理。新夹具保持测试环境和数据库隔离，通过框架Signals可用性接口单独启用Linux PCNTL路径；原 `ScheduleWorkCommand`、循环、信号注册器和回调均保留。就绪检查观察注册器中来自原命令的TERM闭包，避免把进程启动前继承的信号掩码当成命令已就绪。

[短信号实测](../tests/.runtime/queue-lifecycle/run-minute-20261010-045013-1f43f473b0384ecdd67252a9/summary.json)183项检查通过，观察原回调就绪后TERM正常返回0，句柄关闭、无存活后代。[故障清理实测](../tests/.runtime/queue-lifecycle/run-cleanup-20261010-050356-2dec95fac60f9608510d5508/summary.json)在根收到TERM后fork晚生子并立即退出、两个失父子进程忽略TERM时，5.307秒内完成KILL回收，父句柄关闭、无存活后代。

每个已拥有句柄的子进程在独立setsid会话启动；确认同PID/start_ticks及SID/PGID均等于根PID后，只对命中这些会话的进程读取身份，核对UID1000与原PID namespace。退出等待与两个清理窗口持续重扫会话，覆盖失父和晚生成子进程；发信号前重查PID/start_ticks/SID/PGID/namespace，两次空扫描后确认结束。TERM/INT/ALRM触发有界收尾，日志读取使用共享锁。首个完整分钟预算110秒、第二个90秒、worker30秒，整体270秒；这包含现有WSL挂载下PHP多次启动的实际耗时。

## 系统cron当前结果

[独立cron入口](../tests/Integration/CronLifecycle/README.md)已准备私有tmpfs/rootfs、只读应用挂载、合成账号和PAM，复制原生产crontab字节及0644模式；没有修改Host账户或系统配置。实际程序为宿主既有Debian cron3.0pl1-197，尚未运行原7.4镜像的cron。

[本次真实预检](../tests/.runtime/cron-lifecycle/run-20261009-231130-f944909c97a0cb66b25fac7f/summary.json)记录94项通过检查、7.454秒退出、0分钟入口、无打开句柄或清理错误。单HostUID/GID1000映射中，外层 `--map-root-user` 设置 `setgroups=deny`，内层继承；显式allow返回EPERM。该前提未满足，系统cron分钟执行、两次发布和worker链未验收。结果标为BLOCKED，仅限该夹具；整体现代化目标保持活动。

合成账号root/application都映射namespace UID0。本次不证明镜像root切换到application UID1000，也不把上一轮UID1000/PID1结果拼接成该证明。[已有容器停止与镜像路径证据](container-stop-compatibility.md)继续保留各自范围。完整OCI构建运行、Docker自身停止计时、镜像cron、实际挂载权限和正式根依赖切换仍待验收。
