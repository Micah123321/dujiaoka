# 候选真实 Nginx 与 PHP-FPM 验收

第42轮在现有WSL Debian13、普通UID/GID1000上实际运行PHP8.5.11 FPM与Nginx1.26.3-3+deb13u7。两个公开deb包逐一校验索引SHA256及尺寸，只解包到项目测试目录；沿用第39轮同补丁CLI、INI和模块库。两份实际ldd在正确私有库环境下无缺失；[下载与二进制证明](../tests/.runtime/http42/download-proof.json)保留来源、索引hash及未做新OpenPGP核验的准确边界。没有安装系统服务或改店铺配置。

## 原入口与隔离状态

[独立运行入口](../tests/Integration/HttpRuntime/README.md)每次创建新release、SQLite、cache/session/view/storage及两个回环端口。原[public入口](../public/index.php)复制后逐字节相等，vendor映射候选，bootstrap/config/app/resources映射原源；应用base私有且不存在环境文件，五项框架cache路径为本次绝对路径。原项目Composer清单用于应用命名空间判断；执行代码与包发现来自候选vendor。原web路由前缀后仅追加诊断路由，原HTTP kernel、CSRF、会话、异常和代理中间件实际执行。

## 已通过的真实请求

[实际报告](../tests/.runtime/http-runtime/run-20261010-052853-96a9deb08b138929280bd7a6/summary.json)145项检查通过，17个HTTP请求，服务启动后11.655秒。17份客户端响应与17条Nginx JSON访问记录以唯一ID、method、URI、status逐一相等；13个动态请求再与FPM事件一一对应，静态JS/CSS和Nginx直接404边界没有伪造FPM事件。每条动态事件均为fpm-fcgi、Laravel13.35.0、UID/GID1000、原INI与原入口hash，私有DB/storage/base路径一致。

原首页与商品购买页返回合成商品、支付方式及Vue数据，原manifest的JS/CSS响应SHA256与MIME通过。原web中间件无token POST返回419，正确token写文件session，后续请求保持同session/counter1。默认不信任转发头；仅用框架公开接口显式信任本夹具127.0.0.1后，四字段HTTPS/host8443/clientIP实测生效，下一请求无代理状态残留。原异常输出严格JSON500及Server Error，无内部异常细节；真实文件maintenance返回503并恢复首页200。私有Blade/cache/session实际写入，原两条合成订单保持。

两个master收到SIGQUIT并exit0，六份专属SID/PGID/start_ticks身份全回收，四个proc句柄关闭。父脚本[独立复核](../tests/.runtime/http-runtime/run-20261010-052853-96a9deb08b138929280bd7a6/parent-proof-42.json)176checks，重开SQLite、逐项复核三方记录、25份保护源/配置/锁hash、六原身份以及两个监听关闭。

## 故障控制与修正

`--fail-after-start`在两个原生监听及master就绪后主动失败，实际exit1；[失败控制](../tests/.runtime/http-runtime/run-20261010-052853-761dc6e3ea868b2b9297e4c8/summary.json)四句柄关闭、六原身份无存活，两个监听经[独立控制证明](../tests/.runtime/http-runtime/run-20261010-052853-761dc6e3ea868b2b9297e4c8/fault-proof-42.json)13checks检查关闭。外层[原始job退出元数据](../tests/.runtime/http42/launcher-proof.json)保存正向0/控制1/父核验0，[四次端口原始探测](../tests/.runtime/http42/port-proof.json)逐项记录connection refused/errno111及时间，补齐最终审查指出的归档边界。早期包发现缺Composer元数据导致Laravel CLI错误renderer exit0且无summary的产物保留，后续失败正确生成FAIL并exit1，不将退出码单独当验收成功。

首个真实502确认为仅Nginx就绪、FPM尚未监听；现等待双监听与master存活。独立审查指出空原生事件集合、HTML500混入及后续信号判定缺口，现有固定17/17/13及唯一ID关联、严格JSON和中断终态检查。

E_ALL保持。原phpdotenv Reader.php:73使用@file_get_contents读取本次故意缺失的release/.env，PHP error_get_last仍保留被屏蔽的E_WARNING；原值完整留在FPM事件。检查只接受准确file/line/type/本次缺文件message，另要求无PHP引擎错误日志，没有清空last error或静默关闭报告。

## 后续边界

本轮证明候选真实Linux HTTP SAPI与资源读取/私有文件写入，不代表生产FPM镜像、root→application权限切换、完整OCI/Engine停止计时或系统cron。根仍Laravel6/PHP7.4比较树，112/129包图与完整2299/2321/2321回归源未变，复用第39轮验证。真实部署数据库/资源发布、根依赖正式切换、商户及SMTP仍待独立验收；整体目标继续进行。
