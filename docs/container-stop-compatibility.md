# 容器停止契约与PID1排空验收

现有Compose没有外层停止宽限。Docker Linux默认在10秒后强制SIGKILL，而项目queue Supervisor stopwaitsecs90、原DeliverNotification timeout60；容器可能在worker完成前被终止。现在[正式Compose](../docker-compose.yml)明确stop_signal=SIGTERM与stop_grace_period120s，保持原端口、目录和挂载。使用直接Docker命令时需在创建时指定`docker run --stop-timeout=120`，停止/重启覆盖参数也应保留120秒，见[官方停止语义](https://docs.docker.com/reference/cli/docker/container/stop/)和[Compose宽限](https://docs.docker.com/reference/compose-file/services/#stop_grace_period)。

## 真实配置模型

项目内便携[Compose5.6.0](https://github.com/docker/compose/releases/tag/v5.6.0)Windows程序SHA256为8aeaa0055e660e12cf07cd70680dc8333957fe6718528b91ec3da084eab62483，逐下载校验，没有安装容器引擎。[解析入口](../tests/Integration/DeploymentContract/run.php)使用显式空env文件、独立Docker配置、无envresolution/无interpolation及不可连接的本地DOCKER_HOST，仅运行真实config模型。其截止关系取CLI和原任务的较大值，tasktimeout按真实Worker行为覆盖CLI。

[原配置](../tests/.runtime/deployment-contract/run-20261009-222714-883855483c311d22/summary.json)缺显式signal/grace；[旧根最终](../tests/.runtime/deployment-contract/run-20261009-224249-9729c82566c6deca/summary.json)和[候选最终](../tests/.runtime/deployment-contract/run-20261009-224249-5f00a3b2af37e00a/summary.json)各五检查通过：模型SIGTERM、120>90>60、原job60及输入hash未变。三个实际CLI反例：[短10s](../tests/.runtime/deployment-contract/run-20261009-224250-3e3be9b40f27a367/summary.json)、[SIGKILL](../tests/.runtime/deployment-contract/run-20261009-224250-e6947f2d93ee9b48/summary.json)、[缺grace](../tests/.runtime/deployment-contract/run-20261009-224250-9772202c4081dd33/summary.json)各仅相应一项失败。最终断言还实测CLI30/job60：wait90[正常通过](../tests/.runtime/deployment-contract/run-20261009-224251-26be10129bacedf2/summary.json)，wait45[仅actualdeadlines失败](../tests/.runtime/deployment-contract/run-20261009-224251-5e7852200862a9c6/summary.json)，避免错把CLI时限作为job上限。最终[七模型证明](../tests/.runtime/compose40/final-model-proofs.json)按预期返回；独立[只读审计](../tests/.runtime/compose40/audit-40.json)没有P1/P2或通过假象，审计当时指出的分歧分支未验项已补实测。Compose提示原version2.2字段已过时但仍解析成功，保留旧格式。模型验证没有读取店铺环境值或连接引擎，也未验证实际Engine创建时StopTimeout或它的10秒计时。

## 当前基础镜像实物契约

公开7.4 [manifest](https://registry-1.docker.io/v2/webdevops/php-nginx/manifests/7.4)digest为sha256:e316baf2caa9e98ae39985db8203d83defa0422aaf052a5726925cdddd629821，config digest为sha256:ac090046a3304c86ccbb54bd49cfe044b823e9c18b08fb5e814cf03f55730525，amd64/2023-11-29创建/PHP7.4.33。ENTRYPOINT/entrypoint→服务脚本execSupervisord，CMDsupervisord、默认root、applicationUID/GID1000。当前StopSignalSIGQUIT在镜像实际Supervisor代码中也进入SHUTDOWN后给worker发TERM；显式SIGTERM稳定了本项目的停止契约，原缺少外层grace才是实际排空缺陷。

实际cron初始化递归读/opt/docker/etc/cron、0644/补newline/复制/etc/cron.d，再启动cron -f，项目COPY路径有效；Supervisorinclude为/opt/docker/etc/supervisor.d/*.conf，已有queue配置正确。未据历史issue改动有效路径。[逐文件来源、hash、layer校验和限制](../tests/.runtime/container40-source/contract-report.md)保留最终启动文件；关键层11/13/14/15/16/18全流digest已核，初始COPY层12/17没有独立流hash。该镜像Supervisor文件位于Python2.7模块目录，安装包版本未确认；本轮实际namespace使用Python3.13/Supervisor4.3，与7.4镜像完整运行分开记录。

## 原生PID1与application身份

[独立夹具](../tests/Integration/NamespaceLifecycle/README.md)由普通HostUID1000运行，外层私有user/mount namespace绑定本夹具只读合成passwd/group，内层映射applicationUID/GID1000和PID namespace、私有tmpfs0700。原配置user=application/timeout60/tries2/startsecs5/groupstop/90s保留，只隔离路径/队列/console/日志。真实Supervisor为PID1，薄console记录身份后执行原AppKernel、原DeliverNotification和原Guzzle合成provider；没有修改Worker/PCNTL。

[实际报告](../tests/.runtime/queue-lifecycle/run-ns-20261009-223111-0a18a89bb141cdab71f8a2b2/summary.json)297checks，[独立证明](../tests/.runtime/queue-lifecycle/run-ns-20261009-223111-0a18a89bb141cdab71f8a2b2/parent-proof-40.json)核对Host254→NS1、workerHost256→NS3，两者名为application且UID/GID1000。只对实际ownedHostPID/start_ticks/argv和namespace映射一致的进程发SIGTERM；原15秒provider门在停止后的10.534秒仍阻塞、worker和PID1均活，下一任务未reserved/attempt0。10.615秒释放门后原job一次204/txn0/attempt1，worker于10.722秒退出0、PID1于10.819秒内回收0，最终一个未领取job保留、failed0。

所有保护源/config及Host账户hash当次前后一致，cleanuperrors/remainingowned为空；原60秒PCNTL中断与补发可复用[上一轮实证](linux-worker-compatibility.md)。这一轮没有完整OCIrootfs/cgroup/容器构建运行、Docker自身停止计时、cron真实分钟tick、stock7.4镜像Supervisor/root→application权限切换，也未重放本轮保留任务。三平台完整2299/2321/2321与原核心相同，业务与依赖源未变时复用第39轮验证；正式根PHP/框架切换、部署数据库/发布资源与真实商户/SMTP继续待验收。

第41轮补充[候选原生真实分钟调度与系统cron预检](scheduler-compatibility.md)：原生Laravel分钟循环854项检查通过，系统cron因单UID映射继承setgroups=deny仍未触发分钟任务。这些证据与本轮PID1及完整镜像运行分别记录。

[官方模型重放](../tests/Integration/DeploymentContract/README.md)与[namespace重放](../tests/Integration/NamespaceLifecycle/README.md)均保留精确入口。
