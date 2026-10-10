# 完整 Linux 回归与发布诊断观察

本轮将配置、路由缓存的二次 App 观察纳入同一份全新发布回放，补齐当前候选在 Linux 上的完整回归。应用源码、根依赖锁和候选依赖锁均保持上一轮已验收版本，修改集中在外部验收入口。整体依赖升级仍待镜像运行、系统 cron、真实部署 schema 及正式根依赖切换。

## 已实跑的结果

- [Linux 完整 JUnit](../tests/.runtime/release49/linux-candidate-final.xml)实际执行 PHP 8.5.11 / PHPUnit 13.4.1 / Laravel 13.35.0：2331 项测试、18310 断言、零失败/错误/跳过。配置保留 failOnAllIssues，2331 个类名/用例名与当前 Windows 候选逐个一致，见[身份复核](../tests/.runtime/release49/linux-suite-proof.json)。此前 Linux 2323 全套与上一轮 5 个验证码聚焦结果分别保留历史范围。
- [全新独立发布](../tests/.runtime/production-release/run-20261010-085729-d9536fdb0d9b02253d9961c3/runtime-summary.json)通过 545 项检查、57 次实际网络请求、35 次 FPM 动态请求。stage 的 37 项检查确认固定 100 个运行包，29 个开发包未安装；真实 Nginx/FPM、原业务路由、三单创建、队列早到消费、两次过期扫描、Cookie 加密与实际 SQLite 快照均通过。
- [完整独立复核](../tests/.runtime/production-release/run-20261010-085729-d9536fdb0d9b02253d9961c3/parent-proof-49.json)通过 37157 项检查。大部分是逐个源码、载荷、快照与 included-file 校验，并非新增 37157 种业务场景。它以 --framework-observer --complete-observer 显式要求全部原始观察记录。

## 二次引导及原行为对照

原 config:cache 和 route:cache 都会另建 App 并重新安装错误处理器。仅记录第一次 installed=true 的方式会遗漏后续引导；本轮外部[观察器](../tests/Integration/ProductionRelease/bootstrap.php)分别附到两个新 App，在原 HandleExceptions 之后记录未被 PHP 错误掩码抑制的诊断，再将结果交给原处理器。

两个缓存命令的观察适配严格比对供应商 getFreshConfiguration/getFreshApplication 方法体，供应商变动即停止等待复核；继承原 handle、路由获取、配置序列化、路由编译方法。路由引导保留原 Facade 清理/还原以及 Container 还原顺序。适配仅用于夹具外部观察，不改 App、供应商文件或锁。

[十进程正负控制](../tests/.runtime/production-release/run-controls-postboot-20261010-090545-cffb08b31f80644a047892cd/controls-summary.json)通过 79 项检查：Console、HTTP Kernel、配置缓存、路由缓存各有正常引导及真实弃用注入；另外两个不加缓存观察适配的原命令作为基线。故意弃用由原框架接管，子进程仍退出 0，但验收器准确拦下；真实完成标记与 PID 绑定防止框架输出异常而退出 0 时被当成正例。10 份原成功发布的源码/cache/SQL/流量保护哈希不变。

配置缓存原字节完全一致。路由缓存的原始 SHA 确实不同，唯一变化是 Laravel SerializableClosure 的 self 对象身份标记；[对照结果](../tests/.runtime/production-release/run-controls-postboot-20261010-090545-cffb08b31f80644a047892cd/route-cache-comparison.json)仅对该 32 位 self 元数据作按出现顺序的双射规范，其他全部字节一致。两个原始缓存还各自重新加载，并匹配真实首页、购买、验证码路由。控制使用相同的公开 Str 随机工厂固定自动路由名，完整 545 发布回放没有该工厂。首次要求原路由缓存全字节相等的 FAIL67 保留，未追认 raw_identical=true。

## 完整证据的边界

[独立验证器](../tests/Integration/ProductionRelease/verify-transactions.php)重开全部 17 个 PHP 命令记录：13 个确实引导 App，配置/路由缓存各观察两个不同 App ID，其余各一个；4 个按原设计直接使用 PDO 安装/移除故障、调整早到任务与新订单年龄，要求 identity=null、无框架引导、明确原脚本/action/run 参数及相应原始动作证明。35 个 FPM 请求各观察一个原发布 App，所有命令/请求的 PHP 诊断、PID、路径、vendor、included files 均明确核对。

初版复核错误地要求四个纯 PDO 辅助命令也有 App，且漏列 Cookie 入口文件，实际失败后按原流程修正；独立审计同样发现这两项，终稿 37157 已通过。旧第一 App 档案在 complete 模式中应非零并停止写新证明，历史报告保留原范围。

[镜像绑定复核](../tests/.runtime/release49/current-image-binding.json)逐个验证已保存的 1271 个镜像输入哈希与本轮 545 接受发布相同，包括原实际发布的 850 个 Dcat 资产；复用 3832 项输入证明，不把输入准备称为镜像 build/run。当前 Windows/WSL 未发现 docker/podman/nerdctl/buildah/buildctl 或常用 Engine socket；临时 user/mount namespace 的 id 探测已成功并退出，只有 unshare 命令存在，未安装宿主工具或启动 Engine。该能力探测不证明多 UID 映射、完整镜像运行、stock 权限下降或系统 cron。

重放使用已记录的私有 Linux PHP/INI。先运行 [stage.php](../tests/Integration/ProductionRelease/stage.php) --from-installed 指向一份已完成的固定锁副本，得到新 owned run；再运行 [run.php](../tests/Integration/ProductionRelease/run.php) RUN --transactions；完成后运行 verify-transactions.php RUN --framework-observer --complete-observer。完成副本保留原字节，不重复消费。
