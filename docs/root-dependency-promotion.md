# 正式根依赖切换

第54轮已将正式根升级到已验组合：PHP8.5.11 / Laravel13.35.0 / Dcat X2.0.4 / Tinker3.0.2 / PHPUnit13.4.1。约束允许PHP^8.4，当前实际验收版本为8.5.11。100运行包和29开发包的完整锁元数据与原候选逐项相同；通过真实Composer Locker重新计算正式根content-hash，没有重新求解或漂移版本。

[正式清单](../composer.json)保留原App、Tests、helper、seeds/factories classmap及原Composer hooks；[正式锁](../composer.lock)和[根Dockerfile](../Dockerfile)同步切换。镜像基底固定为原接受PHP8.5.11 native manifest a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6，Composer实际锁/平台检查和SIGTERM写入构建入口。已有Compose使用build: .及120秒停止宽限。

根[测试入口](../phpunit.xml)升级PHPUnit13的source语法、failOnAllIssues/no-history，并纳入原六项SMTP、四项表格协议与十二项维护期运行边界。根环境继续使用tests目录、SQLite内存、array缓存/邮件/会话、同步队列及专用storage；后台权限断言根据实际安装的Dcat X判断，不再用候选标记切换。当前根不定义DUJIAOKA_FRAMEWORK_PROBE。

以下 tests/.runtime 链接指向已忽略的本地历史验收档案，新克隆不包含这些文件；可重放命令见[测试说明](../tests/README.md)。第54轮Windows与Linux使用真正正式根vendor/bootstrap完整通过2331项/18310断言，全部class/name身份与原接受第49轮候选逐项相同，无failure/error/skip及PHPUnit issues。见[完整身份对照](../tests/.runtime/promotion54/full-root-suite-proof.json)、[Windows JUnit](../tests/.runtime/promotion54/windows-root-final.xml)及[Linux JUnit](../tests/.runtime/promotion54/linux-root-final.xml)。

真正Composer post-autoload-dump先执行原Illuminate回调，再执行原@php artisan package:discover。专用三缓存sentinel在Composer启动前存在，实际artisan子进程引导前全部消失；子App重建服务和包清单，原HandleExceptions之后诊断为空、raw last error为null。空合成env、storage及缓存均在promotion54，原shop bootstrap缓存SHA不变。见[原hooks证据](../tests/.runtime/promotion54/hook-proof.json)。post-root-package-install与post-create-project原定义保留，本轮未执行这些环境创建动作。

首调用用了实际不存在的php85/php.ini，平台检查及时拦住缺扩展，未运行成有效测试；已改用真实[完整INI](../tests/.runtime/php85-fixture.ini)，保持TLS和平台检查。第一次hooks观察注册早于Composer loader，第二次缺合成env留下Dotenv抑制的raw warning；最终使用空合成文件、重排观察并增加子App前缓存快照。原失败/不完整记录保留，[最终hooks证据](../tests/.runtime/promotion54/hook-proof.json)仅计完整实际角色。

[正式依赖证明](../tests/.runtime/promotion54/root-dependency-proof.json)包含当前两锁、完整129实际安装版本及source/dist引用。旧根七份当前源码、精确旧vendor原目录及逐文件byte校验备份位于忽略的promotion54，保留用户已有修改；回退时应配套恢复旧清单、锁、镜像、测试入口及vendor，不能单独替换一个锁文件。本轮不修改真实数据库、商户配置或队列整数失败ID默认策略。

正式根no-dev发布准备使用固定--root-manifest选项，原字节复制当前清单/锁并记录来源SHA；复用旧100运行包前检查两锁完整packages/packages-dev相同、旧stage PASS及全部原进程关闭，再执行真正Composer install/platform。候选默认入口与历史证明保留各自来源；旧候选发布证据不会追认为新根发布成功。新根实际准备55checks、运行545checks/57真实HTTP/35FPM通过；全部17原PHP命令（13 App角色/4直接PDO角色）、配置与路由缓存二次App及全部原SQL、Cookie、延迟任务和进程关闭的独立父复核37173通过。实际建立三订单，保留两条未来延迟任务，不将提前处理误认墙钟到期。见[新根stage](../tests/.runtime/production-release/run-20261010-115511-a2b4e4ee4513aaa708355fc9/stage-summary.json)、[运行合同](../tests/.runtime/production-release/run-20261010-115511-a2b4e4ee4513aaa708355fc9/runtime-summary.json)与[独立根父证明](../tests/.runtime/production-release/run-20261010-115511-a2b4e4ee4513aaa708355fc9/parent-proof-49.json)。父文件名沿用原harness，profile明确root、两来源SHA是当前正式根，未复用第49轮历史父结果。两个[真实串用负对照](../tests/.runtime/promotion54/root-profile-negative-proof.json)分别在显式profile绑定和历史candidate保护处exit1。

完整Engine构建、运营系统cron和真实商户发布是后续环境动作。本次正式本地根切换无需以这些独立动作作前置，当前文档不宣称已完成生产部署。
