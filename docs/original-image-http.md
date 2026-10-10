# 固定原镜像的完整 CLI 与 Nginx/FPM 验收

第51轮在全新副本完成修正后的 CLI 控制器回放，再用另一份原镜像启动其自带 Nginx 与 PHP-FPM。CLI 冷引导144与默认完整父校验39274通过；Web 合同8095、33个真实HTTP请求、11次FPM请求通过，独立父复核44281通过。两服务SIGQUIT退出0、监听关闭，20个SQLite表的结构和行保持。检查数量主要来自文件与包含源码核对，不等于这些数量的独立业务场景。

## 当前来源与结果

固定原始清单、20层及OCI差分范围仍是[第50轮原镜像证明](native-image-runtime.md)，manifest a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6、config b80ffe888ac6dddfabfb22e74d98aa5e9fef111b09065cea0a2a9d8aedac6521。每份新副本复制前后核20原gzip SHA/size，解压20DiffID和完整链96d70a175288ef6949494a666cd9c6664a011a8aca4376468d3e6c54a6952d40后才归并32049条目。当前CLI与Web各自独立，不追加运行到已完成或失败根。

[CLI100448运行](../tests/.runtime/modern-image/run-layers-20261010-100448-33e809edce76718850c7988d/native-runtime-summary.json)是真正修正后完整PASS，无失败/清理错误，原PHP8.5.11/Composer与Laravel13.35.0冷引导144再次通过。[默认完整39274父证明](../tests/.runtime/modern-image/run-layers-20261010-100448-33e809edce76718850c7988d/native-parent-proof-bound-final.json)无需恢复参数，原50PIDnamespace失败与恢复证明保持其历史范围。

[Web102907运行](../tests/.runtime/modern-image/run-layers-20261010-102907-23bd3800b9437b333b543668/http-runtime-summary.json)PASS，[原Web合同](../tests/.runtime/modern-image/run-layers-20261010-102907-23bd3800b9437b333b543668/http-contract.json)8095检查，[当前完整父证明](../tests/.runtime/modern-image/run-layers-20261010-102907-23bd3800b9437b333b543668/http-parent-proof-bound-final.json)44281检查。独立复核重开原镜像每个文件SHA、原1273上下文/12623vendor全集与当前源码、每次实际FPM包含源码、完整归档和live原日志，SQLite query_only核20表结构/行，再解码实际120x36验证码。

新[Linux内部复用](../tests/.runtime/image51/local-copy-proof.json)仍逐1271接受源/1273上下文与12600包源码原SHA、100原包版本/reference和12623完整文件集合复制校验。第一次准备实际38.299秒；它避免旧约7分钟DrvFS包逐文件搬移，但不复制旧SQL、缓存、环境文件。23个原Composer生成项与包源码区别记录，再由真实原Composer生成；Web期间整份vendor12623字节不变。

## 实际 Web 路径与生命周期

[内部合同](../tests/Integration/ModernImage/http-contract.php)使用原/usr/local/sbin/php-fpm与/usr/sbin/nginx，原/usr/local/bin/php和镜像conf.d/扩展。原AdminData及原SQL按字节放/probe/seed，引导合成账号/无商户凭据的新SQLite，配置现代主题、文件session与缓存，实际执行config:cache、route:cache、view:cache。运行观察器[位于App之外](../tests/Integration/ModernImage/http-prepend.php)，每请求在Composer之前注册、原HandleExceptions之后捕获，并委托原handler。实际唯一/app/vendor、生产环境、缓存启用、PHP8.5.11/Laravel13.35.0、无Tests/dev类或外部loader，11个真实App记录均无观察到的PHP诊断。

33个客户端请求包括首页和购买页200、120x36真实验证码、Vue清单JS/CSS原SHA及类型、后台登录页、无CSRF419、原合成账号登录200、授权商品列表200、退出302后列表302、所有HTML实际引用的18个管理JS/CSS，以及4个公开文件边界404。其中/.env和/vendor/autoload.php由Nginx处理，另外2个404也经过原App。客户端、Nginx access、FPM access及App观察四方request_id/URI/method/status/执行script与worker逐一对应。

两原服务有原exe、PID/PPID/starttime与UID/GID证据。全部HTTP处理后等待真实11个shutdown记录与11个原FPM access齐，再向仅owned proc handle发SIGQUIT；FPM/Nginx均exit0且closed，外层user/mount/PID namespace也closed0，独立父再核原startticks身份消失与两个实际监听已关闭。

## 修复与保留记录

[独立审155](../tests/.runtime/image51/subagent-155.json)发现父CLI只遍历可变清单、缺跨run产物绑定。默认[CLI验证器](../tests/Integration/ModernImage/verify-native.php)现核真实接受上下文和vendor的完整键集及1273/12600/23/144数量，stage/runtime/unpack的run/rootfs/marker，root内与归档合同及验证码原字节。四个[实际候选反例](../tests/.runtime/image51/controls-8f3b179d1d25ca44cc37a1dc/summary.json)分别删一个原输入、删一个原包文件、替换原50运行记录、替换原50实际合同，均exit1并在准确矛盾处失败；原成功档案SHA不变。第一次UID1000私有bind测试被mount的superuser检查阻止，不计为负控制成功；后来用独立候选JSON读入校验，没有覆盖原完成文件。

[独立审156](../tests/.runtime/image51/subagent-156.json)发现父Web验证缺业务角色、完整日志集合和worker/owner绑定。[最终父Web验证器](../tests/Integration/ModernImage/verify-http.php)增加11个原动态路径/方法/状态的完整顺序与正文/CSRF/实际登录结果、Vue清单完整资源、所有live日志/响应键和归档SHA、原PHP诊断、原FPM master/worker的PID/PPID/exe/starttime、执行script/method和Nginx upstream_status、外owned identity。修改后实际重新验证44281通过。

首次Web根[100724](../tests/.runtime/modern-image/run-layers-20261010-100724-188e9a353b82338452f62160/http-runtime-summary.json)在外内同名started标志冲突处退出；第二根[102050](../tests/.runtime/modern-image/run-layers-20261010-102050-90dd6a4ae395e827c3c23d23/http-contract.json)原服务已启动，真实首页302到/install，按原DujiaoBoot和原生产夹具补合成install.lock到新根。第三根[102441](../tests/.runtime/modern-image/run-layers-20261010-102441-73a0d3c677a7ad487fa65737/http-contract.json)33请求和11原access正确、SQL全同、SIGQUIT0，但最后PHP shutdown记录仅10而FAIL。fastcgi_finish_request先返回HTTP，收尾前现在等待完整记录；原FPM%r实际是/index.php，改原支持的%{REQUEST_URI}e记录真正URI，%f继续保真实frontcontroller。三个失败完整保留，第四新根才完成8095与44281。

第52轮另在新原副本完成[原Supervisor/队列/调度](original-image-queue.md)：63383/15events/16PHP/4worker/17command及独立53317通过，原队列重试与调度子PHP在原镜像libs/INI下真实运行。仍是root测试配置，未将其计作stock权限或系统cron。

## 仍待验收的范围

当前在user/mount/PID namespace中UID0仅映射宿主1000一个ID，Nginx显式user root、FPM显式-R/root pool是测试配置。它证明原镜像服务二进制、库、扩展与当前应用真实兼容，不证明stock entrypoint、application1000降权、原chown/多UID权限、系统cron、Dockerfile完整build或Engine启动停止。特殊metadata权限限制仍沿原层报告。正式根依赖切换与真实部署schema/provider仍按既定后续阶段继续，本轮无宿主安装、真实商户/SMTP/DB、commit、push或部署。

重放使用新的原层副本、原DiffID归并、`stage-native.php NEW_RUN --from-native CLOSED_PASS_NATIVE_RUN`，CLI执行`run-native.php NEW_RUN`/`verify-native.php NEW_RUN`；Web另建新副本，经`run-http.php NEW_RUN`/`verify-http.php NEW_RUN`实际验收。完成或失败run不复用。
