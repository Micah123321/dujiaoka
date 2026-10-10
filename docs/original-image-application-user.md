# 原镜像 application 用户运行验收

第53轮在固定20层原镜像的新独立副本准备application UID/GID1000运行，目标是保留原Supervisor程序的`user=application`，并在运行原PHP之前清空有效、许可、继承、ambient、bounding能力及设置NoNewPrivs1。原PHP[身份预检](../tests/.runtime/image53/original-application-preflight.json)实际通过，[完整原队列](../tests/.runtime/modern-image/run-layers-20261010-111945-c1d7869cf22244ef63d70ca1/queue-contract.json)63404内部检查和独立53614项检查通过。包含15个队列事件、16PHP、17原命令、4worker、27SQL快照/22表及25个实际owned身份；数量主要是文件/来源核对而非数万业务场景。[最终独立父证明](../tests/.runtime/modern-image/run-layers-20261010-111945-c1d7869cf22244ef63d70ca1/queue-parent-proof-application-final.json)53614项实际通过，按当前profile订正初证明的旧scope文字，另写不可覆盖的application-final；原运行未重复。

## 身份与实际边界

[外启动入口](../tests/Integration/ModernImage/namespace-runtime.sh)用unshare单映射1000→外部1000保留本命名空间准备mount/chroot的能力，进入原rootfs之后由原`/usr/bin/setpriv`清五类能力、禁止新的特权，再执行原`/usr/local/bin/php`。原镜像passwd/group中application映射到1000，未替换账号文件；预检实读原PHP8.5.11、UID/GID1000、原账号名、原/proc/self/uid_map和gid_map、CapEff/Prm/Inh/Amb/Bnd五零与NoNewPrivs1，进程退出0。

这是实际application账号和无能力进程；原解压器物化所有文件为外部UID1000，单映射不能同时代表root0与application1000两个不同owner。补充组中的未映射ID仍显示65534。因此当前身份、业务运行、文件字节和退出证明与stock root→application降权、原root-owned系统文件权限、多UIDchown和系统cron区分。预检没有声称后几项通过，没有宿主安装或改Host账户/配置。

[外控制器](../tests/Integration/ModernImage/run-queue.php)只允许无额外参数的固定root档或`--application`固定application档；外部profile的UID、GID、maps、账号和能力模式必须与精确两枚举之一相同。新profile在任何服务前绑定新根marker，未知参数或已运行根在复制/启动前停止。应用程序、vendor、原INI和Supervisor Python源保留，不改App/vendor/部署原配置。

[原队列合同](../tests/Integration/ModernImage/queue-contract.php)及[观察器](../tests/Integration/ModernImage/queue-prepend.php)沿[52完整队列状态链](original-image-queue.md)保全部正常、重复、已支付、未来、三次返券回滚/原UUID retry、真实queue:restart/ctl控制和两原schedule子命令。application档只替换程序的私有日志路径和PHP prepend前缀，原user=application保字节；root档仅保历史隔离root替换。所有PHP角色及owned进程身份需与档案绑定，application档五能力零、NoNewPrivs1；父复核另读原raw状态，而非依据声明推断。

[九项实际guard正负控制](../tests/.runtime/image53/profile-controls-74.json)使用真实application seed raw状态为正基线，各改一项CapEff/Prm/Inh/Amb/Bnd或NoNewPrivs，再检查未知UID/额外profile字段均准确停止；原档案字节未动。另[默认root档实际负控](../tests/.runtime/image53/cross-profile-negative.json)在该真实application成功档案的root-UID条件处exit1，不把两种身份结果互认。

17原命令均closed0，Supervisor仅TERM15、4worker/group和namespace结束、socket移除；未来任务原行保留、failed_rows0，三次原QueryException完整回滚，原失败UUID retry恢复、两次原调度均有真实orders:expire子命令，原App/两锁不改。身份记录原raw status/uid_map/gid_map/原passwd group账户，每个PHP本身及25实际祖先/worker状态都核五能力零与NoNewPrivs1，config:cache二次App与其余各App仍强观察。

## 正式本地依赖切换范围

[只读审161](../tests/.runtime/image53/subagent-161.json)核当前[根清单](../composer.json)、[候选清单](../tests/Compatibility/Laravel12/composer.json)、[部署Dockerfile](../Dockerfile)及[根PHPUnit](../phpunit.xml)：正式切换尚未发生，根Laravel6.20.42/112包与PHP7.4镜像仍在。根清单、根锁、Dockerfile与PHPUnit配置至少同批调整；根App/Tests相对路径、helper、seed/factory类映射及原Composer hooks需要保留。比较目录的../../../app和../../Tests路径不能原样成为根autoload。

新版Composer post-autoload-dump会清配置缓存且执行原package:discover，候选本来没有根hooks；需在隔离合成环境实跑真实hooks、platform/lock/reference、Windows/Linux完整根测试、no-dev引导，不以比较树通过替代。已验证候选100运行/29开发包及固定原PHP8.5.11镜像可作为同批切换输入，旧源/旧证明保留供回滚比较。

真实商户、生产发布、Engine、stock降权和系统cron属于各自运营条件；审查未找到它们必须先通过才允许可逆本地根依赖升级的代码规则。当前[范围订正](../tests/.runtime/image53/root-switch-scope.json)将这几项继续分别记录，后续先执行本地同步切换与真实hooks/测试，不再用运营缺项额外阻断本地工作。当前整体目标保持活动，未宣称根升级或生产部署完成。
