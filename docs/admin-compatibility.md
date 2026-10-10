# 管理后台双框架验收

第31轮根 PHP7.4.33 / Laravel6.20.42 / Dcat2.0.24-beta与候选 PHP8.2.31 / Laravel12.69.3 / Dcat X1.2.2通过本页所列核心路径。第33轮候选已在PHP8.5.11再次通过；第34轮当前Laravel13.35.0 / Dcat X2.0.4从干净夹具再通过相同43页面检查/8统计/56资源及真实保存，见[当前维护期框架](laravel13-compatibility.md)。使用实际HTTP内核、会话、CSRF、管理控制器、表单和安装组件资源，不代表所有后台操作或正式部署验收。

第36轮四角色扩展权限、批量恢复和真实文件上传已在两平台完成，见[扩展验收](admin-extended-compatibility.md)；本轮原核心后台也各重放43/8/56。当前完整根2183/17952、候选2205/17577，原历史阶段数字继续保留。

第45轮新增[优惠券真实多选关联写入](admin-coupon-compatibility.md)：原控件新建[1,2]→替换[2,3]→清空关系及回填、真实422/419无写入、精确金额/次数0和事务失败回滚均双树通过；各11浏览器checks/4页面/三成功表单POST，独立87/86checks。完整旧根2305/18728、Windows候选2327/18297通过，Linux新增四例4/35聚焦通过；本轮不外推全部后台保存或Linux完整重跑。

## 已修复行为

候选后台原先把本地时间显示成 UTC ISO 字符串，例如本地 12:34:56 变成 04:34:56Z。七种业务模型的数组/JSON 回归在候选树全部失败；[BaseModel](../app/Models/BaseModel.php) 明确保留旧 Y-m-d H:i:s 序列化，独立继承 Model 的 [Emailtpl](../app/Models/Emailtpl.php) 改为复用它。根/候选均通过 [七模型回归](../tests/Feature/AdminDateSerializationTest.php) 7项/28断言。

[文本卡密导入](../app/Admin/Forms/ImportCarmis.php) 在候选 Flysystem3 中会先写入数据，再因 delete(null) 抛错，可能让重试重复导入。现在仅在非空上传路径存在时清理同一 public 盘，并按 CRLF/LF/CR 分行，不依赖服务器换行符。[导入回归](../tests/Feature/AdminImportTest.php) 修复前根1 error+2 failures、候选2 errors+2 failures；修复后每树11项/156断言通过，覆盖空/缺失/null文件字段、多种换行、混合文本中的字符串0、去重、同名其他盘文件保留和空输入错误。显式 admin_url(carmis) 澄清跳转意图；两版 Dcat 原有 location 已自动加前缀，未把它报告成已复现故障。

[首页入口](../resources/views/admin/dashboard/title.blade.php) 在390px视口会把“官网”拆成两行。改为可换行 flex 链接组并禁止单个入口拆字；QQ入口使用现有翻译文字，移除外部图片依赖。最终两个浏览器报告均没有外部请求、本地失败请求或脚本异常。

## 验收范围与证据

[后台浏览器脚本](../tests/browser-admin.cjs) 在两套运行时各完成43次页面检查、8个异步统计响应、56个不同本地资源URL。页面检查包括重复加载验证持久化，以及四个移动视口页面；并非43个不同路由。浏览器为已安装 Chrome154.0.8037.58 / Playwright1.64.0。

- 七类资源 goods、goods-group、carmis、coupon、emailtpl、pay、order 的列表、新建、编辑、详情均实际返回200；另覆盖导入、系统设置、邮件测试和账户设置视图。
- 未登录访问跳转、错误密码422、成功登录、缺失CSRF419及退出后的会话失效均验证。旧框架恢复 intended goods URL，候选回首页；脚本按实际差异继续，不更改业务认证。
- 首页四种统计通过真实 dcat-api/value 加载，桌面/移动各一次：两笔合成订单、完成一笔、成交率50%、支付/未支付各一笔、销售额9元；四个图表实际初始化。
- 分类创建和更新、商品编辑器初始化后保存、LF卡密文本导入及去重，均通过可见表单/开关/确认对话框、真实AJAX、跳转及重载网格验证。三条导入结果为两个不同卡密和字符串0，加原始夹具共四条。
- 系统设置标题通过真实表单及确认弹窗保存到隔离文件缓存，重新请求读回同值；移动侧栏展开及跳转商品列表通过。1440px桌面、390px移动内容边界及四个入口文字不拆行通过。
- 所有本地资源返回200；每项有对应树响应头，主JS/CSS的实际HTTP字节SHA256与相应已安装包完全一致。候选使用 Dcat X resources/dist，未借用根已发布旧资源；真实编辑器插件、字体、图表等由浏览器加载。

| 运行时 | 完整常规测试 | 断言 | 浏览器页面检查 | 本地资源 |
| --- | ---: | ---: | ---: | ---: |
| PHP7.4 / Laravel6 | 2118 | 16948 | 43 | 56 |
| PHP8.2 / Laravel12 | 2124 | 16490 | 43 | 56 |

以上常规测试数字为第31轮PHPUnit9后台修复后的阶段证据；第32轮运行器升级为2124/16493，见[历史工具链验收](phpunit-compatibility.md)；第33轮PHP8.5/Laravel12/PHPUnit13完整2128/16529见[历史维护版平台](php-runtime-compatibility.md)；第34轮当前Laravel13/Dcat X2完整2140/16574，见[维护期框架验收](laravel13-compatibility.md)。

当前候选XML发现所有Unit/Feature、六项SMTP、四项真实表格协议及十二项HTTP/文件缓存/会话边界，无排除。下述JUnit保留第31轮数字；candidate浏览器报告和截图已由第34轮Laravel13/Dcat X2重放更新，固定当前报告为[Laravel13](../tests/.runtime/admin-candidate/browser-laravel13-final-34.json)，第33轮[PHP8.5/Laravel12报告](../tests/.runtime/admin-candidate/browser-php85-final-33.json)继续保留。JUnit为 [root](../tests/.runtime/admin-root-final-31.xml) / [candidate](../tests/.runtime/laravel12/admin-full-final-31.xml)；浏览器报告为 [root](../tests/.runtime/admin-root/browser-summary.json) / [candidate](../tests/.runtime/admin-candidate/browser-summary.json)，均在忽略的运行目录。最终截图：[候选桌面](../tests/.runtime/admin-candidate/dashboard-desktop.png)、[候选移动](../tests/.runtime/admin-candidate/dashboard-mobile.png)、[旧版桌面](../tests/.runtime/admin-root/dashboard-desktop.png)、[旧版移动](../tests/.runtime/admin-root/dashboard-mobile.png)。

本机 Chrome 的桌面 fullPage 截图会在捕获时改变渲染布局，曾显示侧栏遮挡；捕获前后实际元素边界均正确，普通视口截图也正确。最终桌面使用 fullPage=false，不据该截图伪象添加应用CSS补丁。移动完整截图正常。

## 隔离与重放

[启动器](../tests/admin-fixture.php) 仅接受固定回环主机127.0.0.1:8198及后台/资源路径；数据库、文件会话、文件缓存、上传根目录、编译视图、服务/包/路由缓存分别位于 tests/.runtime/admin-root 或 admin-candidate。使用专用缺失dotenv和公开测试密钥；支付、通知、验证码关闭，邮件array。初始化仅清理带精确fixture marker的固定SQLite文件，非空未标记数据库直接报错。

[合成数据](../tests/Fixtures/AdminData.php) 只从发行SQL解析建表列/默认值，插入自建测试管理员、菜单及业务记录，不导入发行账户或真实数据。MySQL类型/索引未完整映射到SQLite，此夹具不证明部署数据库兼容性。本核心43页面脚本仍仅文本框导入；第36轮独立8199扩展浏览器已执行真实图片/public文本上传与原盘删除，见[扩展隔离与范围](admin-extended-compatibility.md)。

安装浏览器依赖（Node20+和已安装Chrome）：

```powershell
npm install --prefix tests/.runtime/browser --no-save --package-lock=false playwright@1.64.0
```

候选完整INI须包含 gd、mbstring、pdo_sqlite、fileinfo、openssl等已声明扩展，使用独立运行配置，不更改系统PHP设置。当前工作区命令：

```powershell
$env:DUJIAOKA_ADMIN_EXTENDED = '0'
$env:DUJIAOKA_ADMIN_DELETION = '0'
$env:DUJIAOKA_ADMIN_STATUS_MAIL = '0'
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/admin-fixture.php --init-isolated-admin --candidate
$env:DUJIAOKA_ADMIN_TREE = 'candidate'
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini -S 127.0.0.1:8198 -t public tests/admin-fixture.php
```

服务运行时在另一终端执行：

```powershell
node tests/browser-admin.cjs --candidate
```

旧运行时使用同一端口前先停止候选服务并确认端口释放：

```powershell
$env:DUJIAOKA_ADMIN_EXTENDED = '0'
$env:DUJIAOKA_ADMIN_DELETION = '0'
$env:DUJIAOKA_ADMIN_STATUS_MAIL = '0'
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini tests/admin-fixture.php --init-isolated-admin
$env:DUJIAOKA_ADMIN_TREE = 'root'
$env:DUJIAOKA_ADMIN_EXTENDED = '0'
$env:DUJIAOKA_ADMIN_STATUS_MAIL = '0'
$env:DUJIAOKA_ADMIN_DELETION = '0'
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini -S 127.0.0.1:8198 -t public tests/admin-fixture.php
```

另一终端执行 node tests/browser-admin.cjs。脚本每次需要干净初始化，写入结果依赖初始两商品/一分类/一卡密；结束停止临时服务。脚本仅阻断外部URL，本地请求从不伪造成功响应。

第36轮已补[四角色权限、恢复与真实上传](admin-extended-compatibility.md)，第37轮补[人工状态邮件/原生worker](order-status-mail-outbox.md)，第38轮补[七资源批删与永久删除](admin-deletion-compatibility.md)各43页面/26操作组/23拒写或输入检查。各批保持自己的版本范围，未在此轮重复核心43/8/56。真实SMTP/商户服务、其余后台操作仍待验收；根public旧Dcat资源保留，正式切换须发布目标组件资源，并完成部署数据库、Linux/container worker/scheduler生命周期验收。整体升级目标继续推进。
