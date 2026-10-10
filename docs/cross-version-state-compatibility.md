# 新旧框架文件状态与后台登录延续验收

第43轮实际通过 PHP7.4.33 / Laravel6.20.42 / Dcat2.0.24-beta 与 PHP8.5.11 / Laravel13.35.0 / Dcat X2.0.4 双向文件状态验收：42个独立进程、32次原HTTP Kernel请求、260项检查，12.243秒。原生文件缓存、会话、加密cookie、后台身份、CSRF、记住登录和反向更新均通过；退出后保留的旧session-only与remember-only也在另一框架上返回匿名。

[完整实跑summary](../tests/.runtime/cross-version-state/run-20261010-054653-59e35e892d4af96a4e791074/summary.json)、[独立323项核验](../tests/.runtime/cross-version-state/run-20261010-054653-59e35e892d4af96a4e791074/parent-proof-43.json)和[实际42 PID退出](../tests/.runtime/state43/process-proof.json)分别保存。独立证明重新打开SQLite、12份缓存源字节和32份会话源字节，验证实际PHP类型及ViewErrorBag，不只比较JSON报告。

## 原生状态链与双向结果

[父入口](../tests/Integration/CrossVersionState/run.php)创建两份随机marker-owned目录：先旧写新读新更新旧读，再新写旧读旧更新新读。每个任务重新启动明确binary/INI的PHP进程，两个autoload不在同一个解释器内混用；原框架类及原App代码实际执行。

[共享夹具启动](../tests/Integration/CrossVersionState/bootstrap.php)固定同一合成APP_KEY、APP_NAME、cookie名、CBC cipher、SQLite文件和文件cache/session路径。两依赖树分别创建config/routes/packages/services/events和编译视图目录；只读取当前私有目录下刻意缺失的环境文件，真实shop环境不参与。session默认serialization=php，两边默认cookie serialize=false、cookie名绑定HMAC前缀和guard登录键均已按原实现核实。

| 原生行为 | 实际验证 |
| --- | --- |
| FileStore缓存 | 两边各自原Cache写入/对方原Cache读取；系统设置forever、typed数组和原GoodsService目录数组；目录命中零SQL、读取前后源文件SHA相同 |
| 类型 | integer0/string0、float0.0/9.0、bool/null、金额字符串、中文和嵌套数组；递归PHP类型指纹及JSON_PRESERVE_ZERO_FRACTION避免JSON归一导致假通过 |
| 原后台登录 | 原Dcat GET登录页提取真实CSRF，原POST用户名密码/remember，通过原guard；原/admin/goods在另一框架返回真实商品网格 |
| 会话更新 | 原EncryptCookies/StartSession读旧cookie和fileSession；sessionID、CSRF、身份与ViewErrorBag保持，revision1→2回原树仍可读 |
| CSRF | 不使用testing环境或来源header短路；错误token419，原token在另一框架真实接受 |
| remember-only | 请求只携带原recaller，不携带session cookie；另一树原SessionGuard恢复管理员并新建会话，viaRemember=true |
| 失效与退出 | 改合成key/损坏ciphertext后匿名；原Dcat logout后，用更新jar以及保留的旧session-only、旧remember-only分别回原树均匿名 |
| 业务状态 | 七业务/关系表全字段有序快照前后相同，独立重开SQLite核对marker与管理员；登录token更新只发生在合成认证表 |

[每任务入口](../tests/Integration/CrossVersionState/worker.php)直接调用原HttpKernel的handle/terminate，写出的cookie原值进入下一任务Request::create，未手工复制加密、序列化或身份算法。观察原FileStore受保护path仅用于取得文件证据，调用的是该原方法；每份writer缓存与每请求会话保存独立原字节快照。

每任务最长45秒、整轮240秒；全部42子进程实际exit0、资源句柄关闭、终态cleanup_errors为空。Bootstrap返回后观察E_ALL实际上报与私有应用日志新增ERROR/CRITICAL/ALERT/EMERGENCY；预期错误CSRF未放宽其他报告。最终限定只读审计未发现具体P1/P2；先前审计指出的旧状态撤销、浮点JSON丢类型及Dcat捕获异常漏报均已补实测。

## 真实复现并修复的后台弃用

初次新框架商品网格返回200，但严格PHP观察捕获六条动态属性弃用：WebUploader的_id/chunk/chunks/upload_column/file和BatchDelete的selectorPrefix。当前公开[包元数据](https://packagist.org/packages/dcat-x/laravel-admin)最新2.0.4仍含该实现；源码未改。两条[永久组件回归](../tests/Feature/AdminRuntimeComponentTest.php)修前在PHP8.5分别实际抛ErrorException，见[修前JUnit](../tests/.runtime/state43/components-before-candidate.xml)。

应用[WebUploader扩展](../app/Admin/Support/WebUploader.php)与[BatchDelete扩展](../app/Admin/Actions/BatchDelete.php)仅声明未类型化的公共属性，继承全部上传/批删方法。[原容器绑定](../app/Providers/AppServiceProvider.php)在两树原Dcat注册之后替换上传器singleton，[原配置工厂](../config/admin.php)选择默认批删扩展，原上传string0/chunk值、文件路径/名称、isUploading/hasChunkFile及删除HTML行为保持。没有过滤弃用或改vendor，也没有加入AllowDynamicProperties。

| 实际完整回归 | 测试 / 断言 | 结果 |
| --- | --- | --- |
| Windows旧根7.4/Laravel6 | 2301 / 18693 | exit0，29.998秒 |
| Windows候选8.5/Laravel13 | 2323 / 18262 | strict exit0，49.431秒 |
| Linux候选8.5/Laravel13 | 2323 / 18262 | strict exit0，328.950秒 |

JUnit：[旧根](../tests/.runtime/state43/full-root.xml)、[Windows候选](../tests/.runtime/state43/full-candidate-windows.xml)、[Linux候选](../tests/.runtime/state43/full-candidate-linux.xml)。两树组件聚焦各2测试/12断言通过：[旧](../tests/.runtime/state43/components-final-root.xml)、[新](../tests/.runtime/state43/components-final-candidate.xml)。既有注入queue/notification失败日志仍为失败恢复案例的预期输出，不表示完整suite失败。

[30份保护源核对](../tests/.runtime/state43/protected-proof.json)仅预期admin配置变动；AppServiceProvider与两个新增应用扩展另作为本轮受测修复。根112包、候选129包及两锁图继续保持，未调整依赖版本。[三平台原身份逐项核对](../tests/.runtime/state43/discovery-proof.json)无缺失或重复，新旧XML增加的永久用例恰为这两条组件测试。

共享绑定修复后，又在原Linux Nginx/FPM夹具从新目录重放：[145checks/17请求新summary](../tests/.runtime/http-runtime/run-20261010-055343-16df63ff418f44c9d1a0b92e/summary.json)、[原父证明入口对新run的176项独立核验](../tests/.runtime/http-runtime/run-20261010-055343-16df63ff418f44c9d1a0b92e/parent-proof-42.json)均通过，17客户端=Nginx17/FPM13，原进程与两监听均退出。该证据保持真实SAPI范围，没有新增跨版本浏览器断言。

## 重放与部署前提

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini tests/Integration/CrossVersionState/run.php
```

运行所需便携PHP/INI及两已安装依赖树沿用[平台验收](php-runtime-compatibility.md)与[候选框架验收](laravel13-compatibility.md)。每次生成新run及独立任务/输出/原字节快照，失败与成功不会覆盖。用[夹具说明](../tests/Integration/CrossVersionState/README.md)区分输入、运行身份及状态范围。

本轮请求是独立PHP进程内原Kernel执行，不声称浏览器cookie策略、网络HTTP或TLS验收；[第42轮原Nginx/FPM](http-runtime-compatibility.md)保留其真实SAPI范围。结论限同key/cipher/cookie名、默认PHP会话序列化和共享文件路径；配置/路由/服务/事件/编译视图缓存应按目标版本重新生成。Redis/database session、实际key轮换、跨SAPI/browser及真实部署storage权限继续另验。

原key、cookie名、会话格式或文件路径变更时，本轮延续证据不自动适用；显式改key与损坏密文控制已经返回匿名。完整OCI/Engine停止计时、stock旧Supervisor身份切换和系统cron、部署数据库/资源、真实商户SMTP及根依赖正式切换仍待各自验收，整体目标保持active。
