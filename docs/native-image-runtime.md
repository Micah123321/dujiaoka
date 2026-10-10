# 固定原镜像的层与 PHP 运行验证

第50轮实际下载固定镜像的全部原始层，完成 OCI 差分归并，并用镜像自带 PHP/Composer 运行当前已接受应用。独立复核通过37993项检查。验证范围为原镜像文件系统与 CLI；完整 Docker 构建、Engine 生命周期、application 用户降权、Nginx/FPM 服务和系统 cron 仍待验收。

第51轮已在[全新完整CLI副本](../tests/.runtime/modern-image/run-layers-20261010-100448-33e809edce76718850c7988d/native-runtime-summary.json)重放修正后的专用控制器，运行状态PASS、无失败/清理错误。原镜像冷引导144再次通过，[严格独立37993复核](../tests/.runtime/modern-image/run-layers-20261010-100448-33e809edce76718850c7988d/native-parent-proof.json)直接通过，未使用--closed-namespace-observation；原50失败档案保持历史范围。新增[Linux内部复用](../tests/.runtime/image51/local-copy-proof.json)约38.299秒，原1273上下文逐SHA及12600包源SHA保持，100运行包来源和整份12623vendor文件集合再次核对，旧SQL/缓存/环境文件未复制。

第51轮[完整CLI与原Web服务后续证据](original-image-http.md)已完成默认39274父核与4实际记录反例，原Nginx/FPM cachedApp33HTTP/11FPM8095及独立44281通过。下文关于服务未执行和专用完整回放待新基线只描述第50轮历史，当前未完成的是Engine、stock权限/入口与系统cron。

## 原字节与文件系统

固定来源沿用[原始清单](../tests/.runtime/modern-image/registry-47/manifest.json) native sha256:a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6 和 config sha256:b80ffe888ac6dddfabfb22e74d98aa5e9fef111b09065cea0a2a9d8aedac6521，未重新选择标签版本。

[匿名层获取器](../tests/Integration/ModernImage/pull-layers.cjs)实际拉取20个 gzip层、431655842压缩字节，流式核对每层清单size/digest并重开落盘字节。Bearer仅存内存，没有保存或输出token及重定向URL。原下载[拉取证明](../tests/.runtime/modern-image/run-layers-20261010-092852-ef64c9dddee1889d08e474e8/pull-summary.json)保留；持久[新副本](../tests/.runtime/modern-image/run-layers-20261010-094323-0fff67dba9296748965247fa/pull-summary.json)逐层复制前后重新校验相同原字节。

[OCI归并器](../tests/Integration/ModernImage/unpack-rootfs.py)先完整读取20压缩流和20解压tar流，全部DiffID匹配后才创建独占根目录。解压1247822336字节、33417归档成员，最终32049条目，链为sha256:96d70a175288ef6949494a666cd9c6664a011a8aca4376468d3e6c54a6952d40，见[归并证明](../tests/.runtime/modern-image/run-layers-20261010-094323-0fff67dba9296748965247fa/unpack-summary.json)。它先应用下层whiteout/opq再写本层，镜像链接逐组件在根内解析，目录FD/O_NOFOLLOW限制物理写删，绝对链接文本仍保原值供chroot使用。归并规则依据[OCI changeset规范](https://github.com/opencontainers/image-spec/blob/v1.1.1/layer.md)与[DiffID定义](https://github.com/opencontainers/image-spec/blob/v1.1.1/config.md)。

[三个真实gzip/tar控制](../tests/.runtime/image50/rootfs-controls-summary.json)通过11项检查：删除仅作用下层，出现在同层新文件后面的opq仍保留新内容；镜像内绝对链接和看似宿主绝对链接都限定在镜像根，实际宿主哨兵字节不变；硬链接inode相同；最终链接替换为目录；目录穿越、绝对entry、越界硬链接和链接循环按预期停止。

所有物理条目由实际Linux UID1000创建，逻辑uid/gid/mode记录独立metadata。普通权限和sticky位保留，setuid/setgid移除；设备/FIFO、ACL/xattrs/capabilities及时间只记录或明确排除。它验证原内容，未声称原多用户ownership完全复现。

## 原镜像 CLI 的实际行为

[准备证明](../tests/.runtime/modern-image/run-layers-20261010-094323-0fff67dba9296748965247fa/native-stage-summary.json)逐1271接受输入校验与当前545发布一致，再复制1273上下文文件及12623个同锁vendor文件到独立/app。固定100运行包、29开发包缺席；复用已安装vendor，不把该步骤称为从网络全新安装100包。两锁与应用源码保持此前版本。

[原PHP合同](../tests/.runtime/modern-image/run-layers-20261010-094323-0fff67dba9296748965247fa/image-contract.json)实际通过144项检查：镜像原/usr/local/bin/php是8.5.11，Laravel13.35.0以production冷引导，唯一/app/vendor loader、无Tests tree；原验证码Provider注册/去重/发布映射，正确/错误/已消费API校验以及120x36实际GD图像通过；镜像原SQLite扩展写读专属标记、Asia/Shanghai时区与引导后诊断均通过。真实PHP扫描镜像自己的conf.d，包含ionCube和原扩展，没有借用此前私有PHP二进制或INI。

原Composer执行与候选Dockerfile相同的validate --strict/--check-lock、install --no-dev/--no-scripts/--no-plugins/优化loader、check-platform-reqs --no-dev命令；[实际平台输出](../tests/.runtime/modern-image/run-layers-20261010-094323-0fff67dba9296748965247fa/composer-platform.stdout)21项success。[安装输出](../tests/.runtime/modern-image/run-layers-20261010-094323-0fff67dba9296748965247fa/composer-install.stderr)明确Nothing to install/update/remove，重新生成loader；禁网下Packagist筛选列表获取被跳过，故不据此声称完成最新在线漏洞筛选。

实际由UID1000创建user/mount/PID命名空间，根内UID0仅映射外部1000一个ID。私有proc与四个必需设备bind提供CLI环境，纯合成环境、SQLite、array邮件和禁网Composer，没有宿主安装或真实部署。当前缺多UID映射工具，按[Docker rootless前提](https://docs.docker.com/engine/security/rootless)仍需newuidmap/newgidmap及相应范围才能验证application1000降权，不以单映射代替。

## 独立证据与已遇到的限制

[父复核37993](../tests/.runtime/modern-image/run-layers-20261010-094323-0fff67dba9296748965247fa/native-parent-proof.json)逐原文件inventory及关键PHP/Composer/入口二进制SHA重开，还核1273应用输入、12600包源码文件、原PHP included边界、实际验证码字节解码、只读SQLite、原进程closed0及startticks身份消失。23个Composer生成文件按角色记录而未要求生成前后SHA相同。数量主要来自文件验证，不等于37993个业务场景。

首次根放/tmp，实际findmnt显示tmpfs，跨WSL调用后rootfs不再存在；copy前guard实际失败，旧归并档案保留执行时范围。后续新marker使用已查ext4的/var/tmp，并重放全部差分验证，未降低guard或改写旧证明，见[诊断](../tests/.runtime/image50/ephemeral-rootfs-diagnosis.json)。

原同namespace生命周期监控遇到本轮主动创建PID命名空间时，记录[控制器FAIL](../tests/.runtime/modern-image/run-layers-20261010-094323-0fff67dba9296748965247fa/native-runtime-summary.json)。真实原CLI已完成144合同，子根closed/exit0且完成标记存在。父复核仅用显式--closed-namespace-observation恢复这一精确失败类别，并独立重开上述全部数据，原FAIL未改成PASS。修正后的镜像专属runner采用owned proc handle及unshare --kill-child；共享同namespace guard保持原规则。[两次实际namespace控制](../tests/.runtime/modern-image/run-namespace-controls-20261010-095834-c9daa9a04ed7d2a44b1d9ba9/summary.json)确认正常child0及独立namespace teardown；清理先TERM、必要时KILL，实际退出signal9记录保留，后代身份不再运行，未把可能待回收的zombie称为已回收。修正runner的完整App重放仍待新基线，当前CLI成功有独立恢复证明。

当前Windows/WSL无现成Engine CLI/socket；本轮原层和CLI推进不等于Dockerfile build、stock启动、Nginx/FPM流量、systemcron或Engine停止验证。下一阶段继续沿固定原镜像执行这些剩余步骤，正式根依赖切换仍保留等待其验收。

入口顺序：pull-layers.cjs可生成新原下载run或--from-pull复制一份完成层；unpack-rootfs.py RUN完成原diffID与OCI归并；stage-native.php RUN绑定接受源和100包；run-native.php RUN运行原CLI；verify-native.php RUN独立复核。每份owned完成或失败run保留，不原地重消费。
