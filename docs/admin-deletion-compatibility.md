# 后台批删与永久删除验收

本轮只覆盖现有七资源的删除链：goods、goods-group、carmis、coupon、emailtpl、pay、order。Dcat 默认 DELETE 接口会将有效记录软删除，再次删除回收记录时永久删除；没有新增动作或其他入口，pay/emailtpl 仍隐藏行删除按钮并保留批菜单。

## 实际修正前证据

旧根真实认证账号只有 DELETE /goods/3*，实际 POST /goods/3,4 加 _method=DELETE 与自己的 CSRF 后，两条回收记录和对应两张图片都消失。前后七表/关系表与两个业务盘 SHA256 证明见[完整浏览器前证据](../tests/.runtime/admin-deletion-root/browser-before-permission-38.json)：最后权限项HTTP200，业务/文件hash发生变化；前十四项reader软/永久删除则403且全状态保持。问题来自逗号批量路径只检查一次，匹配3*后没有逐ID检查4。

邮件模板控制器已有按回收scope添加的恢复动作，却缺少filter scope定义。真实GET回收站后，ID20回收行显示数为0，见[修正前](../tests/.runtime/admin-deletion-root/browser-before-emailtpl-scope-38.json)；加入与其他资源一致的onlyTrashed筛选后实际显示数1，[修正后](../tests/.runtime/admin-deletion-root/emailtpl-scope-after-38.json)通过。

## 删除契约

[资源守卫](../app/Admin/Middleware/ResourcePermission.php)保留原整条DELETE路径检查，再逐ID核验对应DELETE权限；管理员/permission.enable=false仍可删除有效选择。非法或重复ID在进入下游前400，缺权限403。精确单ID权限不会自动变成一条新的逗号批权限，原Dcat权限链继续执行；认证、CSRF和其他资源操作方法保持原链。

七个应用仓库继承[事务删除基类](../app/Admin/Repositories/TransactionalRepository.php)。沿用父deleting预载全选择，在模型连接的事务内按主键升序锁定并重新核对所有成员；缺成员、模型false取消、数据库异常整批回滚。锁前预载的live/trash阶段与锁内状态不一致时停止，避免陈旧软删请求升级成永久删。模板/图片等数据取锁内状态，file数据在模型事件前固定，模型删除完成并提交后才调用原Dcat文件清理。

[商品级联](../app/Listeners/GoodsDeleted.php)与[分类级联](../app/Listeners/GoodsGroupDeleted.php)使用源模型的连接，使相关SQL跟随同一事务。分类仍只批量软删商品，不触发逐商品事件、不联动软删卡密；商品恢复仍不联动卡密，保留既有业务语义。

纯软删允许跟随外层事务回滚；外层事务中包含永久删除会在任何删除SQL前停止，原因是Laravel6没有可靠提交后回调。实际默认Form.destroy没有这层外事务。数据库与文件系统没有共同事务：提交后的文件清理异常由原Form报错，此时记录已永久删除，残余文件需要另行清理；不会声称清理错误可以回滚已提交数据库。底层文件驱动返回值继续由既有Dcat字段处理。

父级四项组合回归在两树各实际4失败/10断言：live变trash与trash恢复live的陈旧删除意图、命名商品/分类级联误写默认库，见[root修前](../tests/.runtime/admin-deletion-parent-before-root-38.xml)/[candidate修前](../tests/.runtime/admin-deletion-parent-before-candidate-38.xml)。原五缺陷修前也两树各5失败/14断言，见[root](../tests/.runtime/admin-deletion-root74-before.xml)/[candidate](../tests/.runtime/admin-deletion-candidate85-before.xml)。当前[删除回归](../tests/Feature/AdminDeletionTest.php)69项421断言与既有64项989权限回归，两树合计133项1410断言通过：[root](../tests/.runtime/admin-deletion-final-root-38.xml)/[candidate](../tests/.runtime/admin-deletion-final-candidate-38.xml)。

独立只读审计另复现一项P2：Form.deleting切换Model连接后，既有Builder仍从A库SELECT，而模型会向B库DELETE，产生另一库永久删除与A图片误清理。父补Builder/model/default三类漂移，两树修前各3失败/6断言：[root](../tests/.runtime/admin-deletion-drift-before-root-38.xml)/[candidate](../tests/.runtime/admin-deletion-drift-before-candidate-38.xml)。基类现用parent.deleting包装保存实际SQL连接，在开启事务前、锁内Query/Model、全部重载模型上核对同一连接；漂移先停止。真实七控制器当前没有此切换钩子，修正的是新共用删除基类的可复用边界。原审计其余18种权限/路由组合与取消探针未发现独立P1/P2；该项由父永久回归关闭。

## 隔离重放

[夹具](../tests/admin-fixture.php)新增 --deletion，只接受固定回环8201与精确marker数据库，独立admin-deletion-root/candidate、会话/缓存/视图和admin/public上传盘。[合成种子](../tests/Fixtures/AdminDeletionData.php)从发行SQL取列/默认值，不加载发行账号；只在此标记库初始化时重建和清理本模式业务盘。三角色分别只读、GET/DELETE七资源、GET商品与DELETE /goods/3*。所有收件人/商户字段仅合成数据，不联系支付或邮件服务。

服务与浏览器在两个终端运行；同一8201端口两套顺序重放，每次先初始化干净夹具：

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini tests/admin-fixture.php --init-isolated-admin --deletion
$env:DUJIAOKA_ADMIN_TREE = 'root'
$env:DUJIAOKA_ADMIN_EXTENDED = '0'
$env:DUJIAOKA_ADMIN_STATUS_MAIL = '0'
$env:DUJIAOKA_ADMIN_DELETION = '1'
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini -S 127.0.0.1:8201 tests/admin-fixture.php
```

```powershell
node tests/browser-admin-deletion.cjs
```

候选先停止root服务，初始化命令加 --candidate，使用tests/.runtime/php85/php.exe及tests/.runtime/php85-fixture.ini，服务环境tree设candidate，浏览器命令加 --candidate。脚本默认选择对应明确PHP/INI；可用 --php 与 --php-ini 覆盖。

[浏览器脚本](../tests/browser-admin-deletion.cjs)直接PDO query_only取七表与coupons_goods全部字段，加业务盘文件SHA256；不以GET接口的业务响应代替存储证明。真实登录/CSRF/页面勾选/批菜单/确认/网格重载与原Dcat资产均从实际PHP HTTP内核取得。故障只在精确标记夹具内向指定合成ID的deleting回调注入一次异常，业务快照之外保存删除事件的PHP/framework/事务层级。

## 验证记录

| 验证 | PHP7.4 / Laravel6 | PHP8.5 / Laravel13 |
| --- | --- | --- |
| 完整 PHPUnit | 2298 / 18678 | 2320 / 18247，strict0 |
| 删除与既有权限聚焦 | 133 / 1410 | 133 / 1410 |
| 新增删除回归 | 69 / 421；原2229身份全保留 | 69 / 421；原2251身份全保留 |
| 真实删除后台 | 43页面 / 26操作组 / 23权限或输入检查 | 同左 |
| 本地资源URL | 38，全部200且匹配root树 | 37，全部200且匹配candidate树 |
| 实际deleting事件 | 47，事务层级全部大于0 | 同左 |

浏览器覆盖每资源真实复选框软删10/11、回收站复选框永久删除20/21、直接混合12/10软硬删除；十四组成功UI批操作先等原Dcat PJAX完成、选中行实际消失，再独立重新访问核对网格；不抢先导航取消组件自己的刷新。商品另有第二项deleting异常的软删和永久删各一次，完整七表/关系表/业务文件hash均保持；再成功重放时卡密40/41跟随商品软删、永久删除对应图片清理。reader七资源软/永久共十四项403，limited两种逗号表示均403，单3合法永久删及卡密42软删；deleter非法keys400、越方法PUT403、错误CSRF419都没有业务或文件变化。组件按DOM顺序提交11,10，脚本按ID集合匹配并保存真实postedPath，没有强迫其采用测试数组顺序。

最终只保留goods4/11/12三张图片：4未获权限、11/12仍软删。两树CLI独立复核七表与关系表全部字段和文件hash，结果与浏览器终态完全一致；errors/unexpectedHTTP/failedLocal/blockedExternal均为空。最终回收站截图已实际查看：[root](../tests/.runtime/admin-deletion-root/deleted-goods-grid-38.png)/[candidate](../tests/.runtime/admin-deletion-candidate/deleted-goods-grid-38.png)。

最终浏览器结果以browser-summary-38为准。初次完整回放曾记录Chrome本地ERR_NO_BUFFER_SPACE/ERR_ABORTED，保留[原报告](../tests/.runtime/admin-deletion-root/browser-final-network-failure-38.json)；补原生刷新同步后从干净基线重放，严格保留全部requestfailed检查，没有过滤这两种错误。

完整报告：[root](../tests/.runtime/phpunit-root-final-38.xml)/[candidate](../tests/.runtime/laravel12/laravel13-final-38.xml)；身份对照：[root](../tests/.runtime/admin-deletion-root/discovery-38.json)/[candidate](../tests/.runtime/admin-deletion-candidate/discovery-38.json)。真实浏览器：[root](../tests/.runtime/admin-deletion-root/browser-summary-38.json)/[candidate](../tests/.runtime/admin-deletion-candidate/browser-summary-38.json)；父独立证明：[root](../tests/.runtime/admin-deletion-root/parent-proof-38.json)/[candidate](../tests/.runtime/admin-deletion-candidate/parent-proof-38.json)。[根112/候选129包图](../tests/.runtime/admin-deletion-package-graphs-38.json)与第36轮锁文本规范化哈希完全一致。

本轮没有新MySQL锁等待实测，锁排序和异常原子性由SQLite真实业务/故障SQL回归验证；第34/37轮MySQL证据各保留自己的范围。没有重新重放历史核心43/8/56、恢复/上传22/28/13和状态邮件9/9，未宣称它们在本轮全量重跑。全部本轮8201服务已停止，未改vendor/依赖锁/真实数据库/发布资源/生产worker或cron。部署表结构、Linux PCNTL/container/Supervisor与正式资源发布、根切换继续属于整体后续验收。
