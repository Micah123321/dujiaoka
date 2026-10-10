# 固定PHP8.5镜像的独立构建输入

第47轮生成独立构建目录并通过[输入证明](../tests/.runtime/modern-image/run-20261010-073240-014b630c6d0745de3e61d5d8/input-proof.json)3823 checks：1268个原接受输入加Dockerfile/.dockerignore共1270文件，含原发布850后台资源、候选原字节锁及root-local App/helper autoload，固定100运行包。checks含逐文件SHA及输入集合核对，不是3823个不同业务场景。状态为INPUTS_VERIFIED/summary PREPARED，imageBuilt=false/engineRun=false；完整镜像尚未构建或执行。

第48轮PHP8.5验证码Provider启动修复后，[新接受源码context](../tests/.runtime/modern-image/run-20261010-082024-a00d2575ff346333c36cb60e/summary.json)从491checks/57HTTP/35FPM通过的[新独立发布](cross-version-queue-compatibility.md)生成；[3832checks](../tests/.runtime/modern-image/run-20261010-082024-a00d2575ff346333c36cb60e/input-proof.json)核对1271允许输入/1273完整文件，保850资产与100运行包锁，包含新Provider及原现代Docker配方。真实独立Compose模型已生成，复用原registry三字节链和相同锁/manifest的Composer校验；旧47 context原字节保留。以下第47证明仍对应其历史源，新的INPUTS_VERIFIED同样没有image build/run或Engine验收。

第50轮[原镜像层与CLI实跑](native-image-runtime.md)已下载全部20层并匹配20DiffID链，归并32049条目；原镜像PHP/Composer实际coldApp144检查与独立37993验证通过，原PIDnamespace控制器FAIL按精确恢复证明保留。以下47/48的“仅元数据/未执行层”描述限定其历史轮次；完整Engine构建、stock权限与服务/cron仍待验收。

第51轮[完整原镜像CLI与Web](original-image-http.md)关闭原控制器恢复缺口，默认39274复核与4真实反例通过；独立原Nginx/FPM33HTTP/11FPM、8095与最终44281通过。单UIDroot测试配置明确，Engine/stock权限/系统cron仍待。

第52轮[原Supervisor/数据库队列与调度](original-image-queue.md)实跑63383/15events/16PHP/4worker/17commands，独立53317证明原退券故障回滚、UUID恢复、队列重启/双调度和完整日志/SQL/进程退出。stock多UID/Engine/systemcron仍待。

## 官方镜像摘要与实际范围

使用[公开registry读取器](../tests/Integration/ModernImage/registry.cjs)匿名pull权限，抓取当前8.5标签索引、唯一linux/amd64 manifest和config原字节，校验HTTP广告digest及sha256引用，无输出或保存认证token；成功快照随后保留，不允许重新覆盖。官方[标签](https://hub.docker.com/r/webdevops/php-nginx/tags)与[项目](https://github.com/webdevops/dockerfile)确认8.5受维护组合。readthedocs标签列表较旧，具体tag存在和metadata以实际registry字节为依据。

[原registry证明](../tests/.runtime/modern-image/registry-47/registry-proof.json)：tag sha256:38e6dc8933d9456c91a5d14f9adc705edebf414a2f5b4110fb6abc36308d7977，native manifest sha256:a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6，config sha256:b80ffe888ac6dddfabfb22e74d98aa5e9fef111b09065cea0a2a9d8aedac6521。config created2026-10-10T05:38:43Z，声明PHP_VERSION8.5.11、application UID/GID1000、Composer2、entrypoint/entrypoint、CMDsupervisord、原StopSignalSIGQUIT。20层共431655842压缩字节，仅manifest/config被抓取，没有下载或执行层；声明版本/UID不当作实际PHP、扩展或rootdrop实跑证据。

[候选Dockerfile](../deploy/modern/Dockerfile)固定native摘要，配套[生成Compose](../tests/.runtime/modern-image/run-20261010-073240-014b630c6d0745de3e61d5d8/compose.yml)明确linux/amd64，避免非amd64主机默默选择另一未验证manifest。保留stock Supervisor入口、原queue/cron路径，未来镜像内启cron和0644、新建缓存/存储/uploads并chown application；整个源码加可读权限解决私有复制目录0700进入镜像后的可读性，运行存储再收为应用/组权限。实际挂载 ownership 与镜像内root→application仍待运行验收。

## 绑定已接受源和固定Composer锁

[上下文生成器](../tests/Integration/ModernImage/stage.php)要求owned marker成功且已收尾的release，将其原App/bootstrap/config/schema/resources/routes/public/deploy与artisan/normalizedComposer固定锁实复制到另一新marker目录。来源为[第46独立production缓存交易](production-transaction-compatibility.md)，不复制既有vendor、tests、runtime storage、bootstrap缓存、环境文件、密钥、uploads/public storage或installlock；符号链接停止生成。

源码摘要取第46原stage的source-copy，已发布Dcat资产取第46原Artisan admin:publish时850hash快照。每个复制文件先确认在原接受快照再读取，实际目标SHA须吻合，最终整个允许文件集合与原快照相同才写PREPARED；context外[accepted-baseline](../tests/.runtime/modern-image/run-20261010-073240-014b630c6d0745de3e61d5d8/accepted-baseline.json)留存本次所用原快照。父[独立verify](../tests/Integration/ModernImage/verify.cjs)再直接重开原第46档案、重建允许集合并逐文件比对，而不是仅和本轮自己的copy报告比较。实际file全集没有新增、丢失、变化；root的旧App/Composer及原46 release都保留。

原两锁仍旧根112包/候选129包；复制锁保持7a45354892b05601f5b8855fc3f08d3b8e742ffecf692469f939284463dc1b4c，129中100运行/29开发。开发包只在lock内记录，future install --no-dev不安装；context目前没有vendor，因此没有声称镜像内100包已安装。PSR App映射app/、helper app/Helpers/functions.php、无Tests autoload-dev，解决直接COPY tests候选的../../../路径以及根dockerignore排除整个tests的冲突。

原Composer在独立Linux8.5/phpINI与私有HOME/cache、禁网/空auth条件下执行validate。严格校验初次因原二维码2.0.0精确约束的语义建议exit1；未放宽该依赖或更新锁。使用原CLI已确认--no-check-all仅跳严格/宽松约束建议，仍--strict/--check-lock校验schema及当前锁；[最终validate](../tests/.runtime/modern-image/run-20261010-072352-ed350ea7e3a809dfb562113d/pwsh-2090.result.json)exit0。独立故意require Laravel12却配13.35.0锁的[负控制](../tests/.runtime/modern-image/run-20261010-072352-ed350ea7e3a809dfb562113d/pwsh-2091.result.json)明确报告hash及版本错误、wrapper非0，证明没有跳过锁要求。不会执行建议输出的composer update。

原check-platform-reqs --lock --no-dev --no-plugins在**现有LinuxPHP8.5**实际21项success，包含两polyfill提供要求；这不是镜像扩展已通过。[源码副本和清单哈希](../tests/.runtime/modern-image/run-20261010-073240-014b630c6d0745de3e61d5d8/host-platform.json)记录范围。两次context的manifest/lock完全同字节，后续仅新增原接受快照与tag链检查，复用该Composer结果。未来Dockerfile明确validate、install no-dev/no-scripts/no-plugins/优化loader、安装后的check-platform；构建时没有bootstrap真实商户、数据库或环境来生成配置缓存。原package discover由应用正常启动建立，生产配置/route/view缓存需要部署环境就绪后按[原release流程](production-release-compatibility.md)执行。850资产来自已接受原发布快照，锁不一致时停止生成。

## 真实模型与待执行构建

当前Windows/WSL常用docker/podman/nerdctl/buildah/buildctl及常用Engine socket都未发现。只用已有便携Compose5.6读取[新模型](../tests/.runtime/modern-image/run-20261010-073240-014b630c6d0745de3e61d5d8/model.json)：准确project-directory新run/空env/禁env解析/独立Docker配置/不可连接127.0.0.1:1；原UTF16LE输出保留，verify按实际BOM解码。检查build.context恰是该context、dockerfile名、native platform、SIGTERM/120s、127.0.0.1:8210→80；原queue90s/timeout60和application cron字节同原。

三bind仅指新run/runtime/.env、install.lock和uploads，create_host_path=false，环境只读；未创建这些运行文件或绑定真实店铺数据。未来在明确环境且Engine可用时，对这个run运行docker compose --env-file empty.env --project-directory . -f compose.yml build，先验镜像原安装包与平台，再独立验证启动、Nginx/FPM、实际stock身份/绑定权限、cron、Engine停止时序。没有执行up/deploy或发布镜像。

首审143发现tag→manifest关联和验收源快照两个P2，均补齐并生成新的完整context；首context/2552check记录保留。最终3823检查加入三原registry SHA/唯一native descriptor/config关系和1268原接受SHA与完整集合。[实际缺源负控制](../tests/.runtime/modern-image/run-20261010-073240-014b630c6d0745de3e61d5d8/missing-source-control.json)先复制完整context成功，再仅删app/Console/Kernel.php并同步删除两份self-index条目，verify实际非0止于original_accepted_release_baseline；原正确context重跑3823通过。副本之前的正确证明单独保留为positive-before-mutation，未把已损坏副本称为成功。生成器PHP命名空间、JS正则、真实Compose UTF16LE/120s表示及CLI路径/PowerShell空auth字面参数的开发失败均在job记录保留并修正，不记成业务缺陷或镜像失败。

[根Dockerfile](../Dockerfile)与[正式Compose](../docker-compose.yml)仍采用原部署路径；这一轮是可审阅候选输入交付。完整OCI/Engine、systemcron/stock身份、实际部署schema/资源及正式根切换继续作为整体活动目标。旧根/候选核心与第46交易/密码/worker/补偿结果保持，应用/vendor/锁未改，按已有SHA证据复用而不宣称重跑。
