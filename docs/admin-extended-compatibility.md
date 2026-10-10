# 扩展后台权限、恢复与真实上传验收

第36轮正式PHP7.4.33/Laravel6.20.42/Dcat2.0.24-beta与PHP8.5.11/Laravel13.35.0/Dcat X2.0.4均从干净隔离夹具完成四角色浏览器验收。各22次页面检查、28项权限/原子性检查、13组登录/菜单/操作验证、55个本地资源URL；既有核心后台也各重放43页面/8统计/56资源。页数包括重载，不表示22个不同路由。Chrome154.0.8037.58与Playwright1.64.0真实认证、CSRF、已安装组件资源和原应用HTTP内核，没有伪造本地成功响应。

第45轮独立8202增加[优惠券关联表单验收](admin-coupon-compatibility.md)，补真实关系新建、替换、清空、金额/次数0与触发器失败原子回滚，未更改本页四角色或上传夹具。

## 已复现并修复的边界

原Dcat dcat-api动作和Widget路由跳过路径权限，再调用默认true的authorize；Widget文件上传/删除也未检查表单授权。仅GET goods/指定详情与import页面权限的reader在两个平台，通过实际页面CSRF与候选页面签名POST Restore，goods3真实恢复；POST ImportCarmis真实新增卡密。候选菜单角色后备检查还忽略HTTP方法，PUT goods1的is_open真实从1改为0。旧根同PUT虽然HTTP200，但正文无权限、is_open仍为1；没有把错误页面200计为写成功。

[修前双平台请求/状态因果证明](../tests/.runtime/admin-permission-before-proof-36.json)保留原始证据。[资源守卫](../app/Admin/Middleware/ResourcePermission.php)现在对七资源与三独立页面要求明确存储的HTTP method/path权限，菜单显示不补足资源写权限；位于[配置](../config/admin.php)web会话之后、Dcat admin组之前，保证旧框架提前返回200错误页前即可403。未登录继续由原认证处理，管理员与permission.enable=false语义保留。

已知ImportCarmis/SystemSetting/EmailTest的submit/upload/destroy-file分别映射原页面POST权限；读取与vendor一致的get参数优先级，保留候选ClassSigner原验证，不接受额外unsigned模式。恢复动作只接受七个实际软删除业务模型，逐ID核验相应资源PUT权限，并在展示/分发/handle检查；[单行恢复](../app/Admin/Actions/Post/Restore.php)只取onlyTrashed，[批量恢复](../app/Admin/Actions/Post/BatchRestore.php)在同连接事务内锁定并预查全部成员后才恢复。无权限403、缺失或非回收成员404，不留下前面成员的部分恢复。

独立只读审计发现Dcat Helper还使用全局真实request路由名：拥有POST dcat-api.form或PUT dcat-api.action的非管理员，即使缺少对应资源权限，映射判断也可能放行。两树各两个新案例实际失败后，守卫将权限检查期间的全局request绑定到映射请求，并finally恢复原对象；两树永久回归验证拒写及原request/route恢复。没有修改vendor匹配器。

## 实际操作与证据

四账号为自建administrator、reader、goods-editor、importer，各独立浏览器cookie/CSRF上下文。reader读商品/指定详情和导入页；商品编辑者可GET/PUT/PATCH商品、恢复商品；导入者仅GET/POST导入。浏览器28项检查覆盖POST/PUT/PATCH/DELETE、POST伪装DELETE、跨资源单/批恢复、有效签名Widget提交/上传/删除、直接跨资源GET、缺失成员批恢复404。每项比较前后goods/carmis完整SQLite业务字段与两个业务盘的文件SHA256，全部零变更。

实际组件生成随机.act选择器，脚本按calledClass识别单/批动作并复用页面生成的完整签名。商品图片通过setInputFiles触发真实自动上传、正常表单保存、编辑页重载预览；1806字节PNG源/盘/HTTP三方SHA256相同。勾选1/2两行后经批菜单与确认软删除，再批恢复及重载；未选goods4仍软删。原GoodsDeleted事件会级联软删卡密，商品恢复不会联动恢复卡密；脚本另通过卡密回收站实际单行菜单及确认还原原card1，保留原业务语义。

导入者选择51字节CRLF文本，经可见上传按钮上传public盘；其间reader/editor分别尝试删除这个真实已存在文件，都403且文件完整保留。确认Widget导入后持久三条：BROWSER-CRLF-A、BROWSER-CRLF-B、字符串0，重复A合并；加原卡密共四条有效卡密。导入源文件由原handler删除，admin盘图片仍保留；随后管理员网格核对实际数据。导入者原redirect指向无GET权限的卡密页，预期403并记录，数据库导入成功仍已独立验证。

| 正式树 | 完整常规测试/断言 | 新权限回归/断言 | 扩展页面/权限/操作 | 原核心页面/统计/资源 |
| --- | ---: | ---: | ---: | ---: |
| PHP7.4 / Laravel6 | 2183 / 17952 | 64 / 989 | 22 / 28 / 13 | 43 / 8 / 56 |
| PHP8.5 / Laravel13 | 2205 / 17577 | 64 / 989 | 22 / 28 / 13 | 43 / 8 / 56 |

JUnit：[root](../tests/.runtime/phpunit-root-final-36.xml)、[candidate](../tests/.runtime/laravel12/laravel13-final-36.xml)；[永久权限回归](../tests/Feature/AdminMutationPermissionTest.php)覆盖真实存储权限/菜单后备、七模型实际Action分发、事务失败回滚、三Widget映射、参数优先级、签名验证与配置语义。完整发现对照[root](../tests/.runtime/admin-root-discovery-final-36.json)/[candidate](../tests/.runtime/admin-candidate-discovery-final-36.json)确认原2119/2141用例逐项保留、仅新增64项，无缺失/重复。候选严格模式无warning/deprecation/notice/risky/skip/incomplete；原Injected queue/notification failure为既有故障注入输出。

父独立只读最终状态证明[root](../tests/.runtime/admin-extended-root/parent-proof-36.json)/[candidate](../tests/.runtime/admin-extended-candidate/parent-proof-36.json)与最终业务快照核对有效商品1/2/3、回收商品4，有效卡密1/4/5/6、回收2/3，只有admin图片保留且public源文件已删除；均与浏览器终态和上传哈希一致。

扩展完整报告：[root](../tests/.runtime/admin-extended-root/browser-summary-36.json)、[candidate](../tests/.runtime/admin-extended-candidate/browser-summary-36.json)；本轮核心报告：[root](../tests/.runtime/admin-root/browser-summary-36.json)、[candidate](../tests/.runtime/admin-candidate/browser-summary-36.json)。两扩展报告errors/unexpectedHTTP/failedLocal均为空；旧根Google字体外部请求被阻断，候选没有外部请求，故未宣称两树都零外部请求。两树上传编辑和回收站截图均实际查看：[root图片](../tests/.runtime/admin-extended-root/goods-picture-editor-36.png)、[candidate图片](../tests/.runtime/admin-extended-candidate/goods-picture-editor-36.png)、[root回收](../tests/.runtime/admin-extended-root/goods-recycle-bin-36.png)、[candidate回收](../tests/.runtime/admin-extended-candidate/goods-recycle-bin-36.png)。

## 隔离与重放

[夹具启动器](../tests/admin-fixture.php)的--extended仅使用固定回环8199与admin-extended-root/candidate目录，独立SQLite、文件会话/缓存、两上传盘、编译视图及配置/包/路由缓存，避免覆盖8198核心夹具及历史固定报告。[数据种子](../tests/Fixtures/AdminData.php)默认false保留核心数据，扩展才增加角色/权限/软删除记录；仅从发行SQL取建表列/默认值，不加载发行账户。初始化仅在精确marker验证后的本夹具重建SQLite并清理其admin/public业务盘，非空未标记库终止。

图片/文本HTTP路径使用选中隔离盘的真实文件字节，验证canonical path与扩展名；没有把root public已发布资源当候选资源。inspect CLI只读合成goods/carmis、上传文件hash及真实PHP/INI/framework/database/storage。浏览器SQLite快照使用query_only直接PDO，避免诊断读取带来框架bootstrap或seed副作用；页响应头另外断言选中HTTP runtime和PHP版本。

每套先初始化，再在独立终端运行服务，完成浏览器后停止该服务。同端口重放另一套前先释放8199；已有Chrome且tests/.runtime/browser安装Playwright1.64.0。

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini tests/admin-fixture.php --init-isolated-admin --extended
$env:DUJIAOKA_ADMIN_TREE = 'root'
$env:DUJIAOKA_ADMIN_EXTENDED = '1'
$env:DUJIAOKA_ADMIN_DELETION = '0'
$env:DUJIAOKA_ADMIN_STATUS_MAIL = '0'
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini -S 127.0.0.1:8199 -t public tests/admin-fixture.php
```

```powershell
node tests/browser-admin-extended.cjs --php tests/.runtime/php74/php.exe --php-ini tests/.runtime/php74/php.ini
```

```powershell
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/admin-fixture.php --init-isolated-admin --candidate --extended
$env:DUJIAOKA_ADMIN_TREE = 'candidate'
$env:DUJIAOKA_ADMIN_EXTENDED = '1'
$env:DUJIAOKA_ADMIN_DELETION = '0'
$env:DUJIAOKA_ADMIN_STATUS_MAIL = '0'
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini -S 127.0.0.1:8199 -t public tests/admin-fixture.php
```

```powershell
node tests/browser-admin-extended.cjs --candidate --php tests/.runtime/php85/php.exe --php-ini tests/.runtime/php85-fixture.ini
```

核心8198服务须显式DUJIAOKA_ADMIN_EXTENDED=0或清除该变量；核心/扩展服务均须DUJIAOKA_ADMIN_STATUS_MAIL=0，避免终端继承8200状态邮件模式。全套根PHP7.4与候选PHP8.5命令使用[主回归说明](../tests/README.md)。

此轮没有MySQL锁竞争/部署数据库新证明，不重复代替第34轮真实MySQL证据；没有浏览器穷举七资源全部批操作、永久删除、人工订单状态邮件或真实SMTP/商户投递。根依赖/public旧Dcat资源继续保留；Linux PCNTL/container/Supervisor与真实部署schema、正式资源发布及根切换仍属整体后续验收。临时8198/8199服务已全部停止，整体升级目标继续进行。
