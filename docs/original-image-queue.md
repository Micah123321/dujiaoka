# 原镜像数据库队列、Supervisor 与调度验收

第52轮在另一份固定原镜像副本运行原 PHP8.5.11、Supervisor4.2.5 与 Laravel13.35.0 的实际数据库队列、原 schedule:run。第三新根已完成63383项内部检查：15个真实队列事件、16份PHP运行记录、17条原命令、4个Supervisor worker身份、27份完整SQL快照。[独立父复核](../tests/.runtime/modern-image/run-layers-20261010-110739-44d68085dfb420f0617da1d9/queue-parent-proof.json)53317项检查通过，重开原文件、原日志及最终SQLite。数量主要是源码和文件核对，不等于这些数量的业务场景。

## 当前来源与业务流程

沿用[原镜像层证明](native-image-runtime.md)和[上一轮完整CLI/Web](original-image-http.md)固定manifest a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6、20压缩层与20DiffID链、32049归并条目。三个根均独立复制和解压原始层；[当前110739副本](../tests/.runtime/modern-image/run-layers-20261010-110739-44d68085dfb420f0617da1d9/queue-runtime-summary.json)与旧CLI/Web、前两队列尝试分开。准备逐1273接受上下文/12600包源SHA和完整12623vendor/100runtime版本与reference核对，未复制旧数据库、运行缓存或凭据。

[内部合同](../tests/Integration/ModernImage/queue-contract.php)从原Helper与原安装SQL建新SQLite，另建Laravel原数据库jobs与UUID失败夹具表。项目[默认失败驱动](../config/queue.php)继续database，既有整数失败ID/旧无UUID载荷兼容结果见[双框架队列迁移](cross-version-queue-compatibility.md)；当前UUID夹具验证是新原运行环境的专项，不作部署表迁移。

四个自有合成订单与券来自原字段模板，真实期限与时间未冻结。所有业务表均逐行核对，只有指定order状态/coupon_ret_back/更新时间和指定coupon.ret/更新时间在对应阶段改变。

| 原命令流程 | 实际检查 |
| --- | --- |
| 原数据库worker正常消费 | 过期状态与返券原事务、任务删除，无失败记录 |
| 原重复载荷再消费 | 原已过期订单、券及其余业务行保持 |
| 已支付订单消费 | 原已完成状态、券与所有业务行保持 |
| future队列原worker --once | 真实available_at在未来3600秒，未reserve或执行，原payload/attempts与全表保持；未声称等待3600秒 |
| Supervisor消费返券故障 | owned AFTER UPDATE trigger在真实coupon更新处失败，原任务maxTries3覆盖部署--tries2，attempts1/2/3各完整回滚，唯一JobFailed与真实UUID失败行 |
| 原queue:retry UUID与Supervisor恢复 | 撤自有trigger，原失败UUID读取/清除，原command/deadline/UUID保留、nativeattempt归零，原新worker恢复消费且仅返券一次 |
| 原queue:restart与Supervisorctl | 缓存重启信号使原daemon正常退出后autorestart，stop/start共4个不同worker；原PID/PPID/exe/startticks/pgrp与日志绑定 |
| 两次原schedule:run | 明示重置仅自有正常订单/券形成另一个测试阶段，原Console Kernel每分钟orders:expire子命令与文件互斥；第一遍过期/返券、第二遍不重复，future/paid和其他全表不变 |

原[部署Supervisor程序](../deploy/supervisor/queue.conf)仍保留sleep3/tries2/timeout60/startsecs5/autorestart/groupstop/stopwait90，隔离副本仅换user application→root、stdout日志到私有目录、PHP观察器前缀。原[cron配方](../deploy/cron/dujiaoka)字节保留；本轮调度来自原schedule:run，不称系统cron已运行。Supervisor SIGTERM退出0，原PHP CLI和所有ctl自然退出0，4个owned worker/group及外namespace身份消失，私有Unix控制socket关闭。

## 观察与复核

[外部prepend](../tests/Integration/ModernImage/queue-prepend.php)在原Composer之前观察、原HandleExceptions之后委托原handler，并关联原JobProcessing/JobProcessed/JobExceptionOccurred/JobFailed和调度任务事件。正常运行无PHP引擎诊断，三份原QueryException明确记录为owned数据库故障；原App日志只含这三条原异常，未吞掉错误。原config:cache确实另建App，沿已验收观察适配校对原getFreshConfiguration方法体，仅添加新App观察回调，原序列化/handle继承。两App均实际观察，其他角色各一个真实App、唯一/app/vendor和无开发包。

原INI、conf.d与镜像扩展保持；另附私有INI使原scheduler子PHP也载入观察器，全部记录核原scan顺序。335个证据文件完整递归清单含INI与实际完成标志，归档逐文件SHA/size核对。14121个保护来源和1093个合并实际包含源码核原字节，App与两锁保既有接受版本。

[外控制器](../tests/Integration/ModernImage/run-queue.php)默认核终态观察、所有文件集合、完成nonce、实际child0与ownedfinally。[独立父验证器](../tests/Integration/ModernImage/verify-queue.php)另重开完整原镜像文件/上下文/vendor、335证据及其角色参数、16PHP源/INI/observer、15原队列事件、3attempt完整SQL回滚、原重试UUID与27快照，最终query_only核22表全部schema/row/object。

## 原始失败与修复

[首次104311](../tests/.runtime/modern-image/run-layers-20261010-104311-73b4bca0978c672f87d367ee/queue-contract.json)实际16682检查后FAIL：原worker为0却任务重新入队，正常返券SQL不变。原Laravel把字符串函数监听器当成类解析，实际日志Target class miq_job_event does not exist；改为闭包监听，保留该原失败，不修改App/vendor。

[第二110333](../tests/.runtime/modern-image/run-layers-20261010-110333-adf593f3b69eb4513a9b8a93/queue-contract.json)内部63382/15事件/17原命令皆执行，内部控制器写PASS，但[独立审158](../tests/.runtime/image52/subagent-158.json)指出收尾在disconnect后重写seed报告，实际留下getAttribute on null而无cleanup错误，另有观察INI与完成标志漏于文件清单。修为先完整观察/检查、后disconnect，内外默认检查终态字段，递归全清单在完成标志生成后核。对原第二档案的[实际负控制](../tests/.runtime/image52/terminal-observer-negative.json)在准确终态错误处exit1，未生成父证明；该内部PASS保持原历史范围，未计为最终验收。第一次异步父负控只因当前源码在读取时已变而停止，其输出另存、不假记终态负控。

原[三个控制器](../tests/Integration/ModernImage/run-native.php)现补全CLI/HTTP/queue跨入口开始和归档标志，所有已运行根在复制或App启动前停止。[两项真实旧HTTP跨入口负控制](../tests/.runtime/image52/closed-http-control-0.json)得到准确fresh guard非0，原HTTP档案仍PASS且无新的native/queue开始标志。

[最终只读审159](../tests/.runtime/image52/subagent-159.json)独立重算13组状态转换、15原payload SHA和27个rawstat身份/事件关联，无仍成立P1/P2。父验证首轮因原调度子argv0相对artisan而非/app/artisan停止，后按实际原sourceinclude保对应角色；第二轮原Supervisor删socket后原closure函数不写连接日志，父现独立核实际socket路径或残留socket连接失败。两次[真实父失败](../tests/.runtime/image52/parent-expectation-fixes.json)保留，未产完成proof；最终2339才独立53317通过，原第三运行未重复。

第53轮另在[原application用户](original-image-application-user.md)完成原程序user=application不改、UID/GID1000与五能力零/NoNewPrivs1，63404内部及53614独立通过。旧52root配置证据保持原范围；当前单映射未证明root→application和多UID权限，不混合两身份证明。

## 后续实际范围

当前仍是private user/mount/PID namespace，UID0只映射宿主1000一个ID，Supervisor显式root测试配置；原application1000降权/多UID权限/chown/stock entrypoint、完整OCI构建/Engine和系统cron尚未验收。即时[运行能力查询](../tests/.runtime/image52/runtime-capabilities.json)未发现Windows/WSL OCI命令、Linux多UIDhelper和docker.sock；原cron含required pam_loginuid配置。上述事实不由普通原schedule:run通过替代。正式根依赖切换、实际部署schema/provider继续后续阶段，本轮未作宿主安装、真实商户/SMTP/数据库、部署、提交或推送。

本地重放另建原层副本，完整解压/stage后执行`run-queue.php NEW_RUN`及`verify-queue.php NEW_RUN`；已完成或失败根保留，不再次运行。整体目标保持进行中。
