# 优惠券真实表单与商品关联验收

第45轮补齐此前只读页面之后的优惠券写入链。PHP7.4.33/Laravel6.20.42/Dcat2.0.24-beta与PHP8.5.11/Laravel13.35.0/Dcat X2.0.4均通过原表单新建、替换商品关联及清空关联；没有发现需要更改应用表单或业务仓库的缺陷。新增[四项永久原Kernel回归](../tests/Feature/AdminCouponFormTest.php)、[真实Chrome驱动](../tests/browser-admin-coupon.cjs)及[原后台夹具](../tests/admin-fixture.php)的独立--forms模式。原[核心后台](admin-compatibility.md)、[四角色上传与恢复](admin-extended-compatibility.md)和[删除验收](admin-deletion-compatibility.md)保留各自范围。

## 实际提交与持久状态

[原Coupon表单](../app/Admin/Controllers/CouponController.php)使用multipleSelect与customFormat回填，[原模型](../app/Models/Coupon.php)通过coupons_goods的coupons_id/goods_id关联Goods。浏览器操作可见Select2选项与移除按钮、真实提交按钮、原AJAX JSON、网格跳转及再次打开编辑页；未直接设置select以代替可见控件，也未拦截本地成功响应。

- 新建关闭状态的合成券BROWSER-COUPON-45，discount1.25、ret2、is_use1，商品关联准确[1,2]。
- 编辑同一id2，移除商品1、选商品3，关联准确[2,3]、discount2.50、ret0；编辑页重开回填一致。
- 通过两个可见选择标签移除所有商品，实际序列化载荷保留原模板hidden `goods[]=`，关系归空；再次打开390px编辑页仍为空，金额2.50和次数0保持。
- 原券id1与其商品1关联保持，六张其他业务表所有字段不变。缺coupon真实422且含该字段错误、缺CSRF真实419，两个控制均比较全八表零变更。退出后访问优惠券页真实302。

每树原表单三个成功POST的请求载荷、原200/status=true/data.type=success响应字节均留档。根报告[11checks/4页面/31资源](../tests/.runtime/admin-forms-root/run-1791614617203-15d72411d497/browser-summary.json)，候选报告[11checks/4页面/30资源](../tests/.runtime/admin-forms-candidate/run-1791614667582-98061837729b/browser-summary.json)；页面数包括同一路径重开和移动视口，不是四个不同资源路由。原Dcat主JS/CSS响应SHA与对应已安装vendor一致，全部本地资源200，无pageerror、unexpectedHTTP或failedLocal。本轮两树blockedExternal均为空；阻断器仍仅对外部URL生效，没有伪造本地响应。

每树六快照为before、after-validation、after-csrf、after-create、after-replace、after-clear，使用独立PDO/query_only事务读取coupons/coupons_goods/goods/orders/carmis/pays/emailtpls/goods_group所有字段，原marker验证后只读；不加载框架、不写seed或package/sessioncache。父独立重开载荷/原响应/六快照/对应DB与资源hash、实际8202关闭，根[87checks](../tests/.runtime/admin-forms-root/run-1791614617203-15d72411d497/parent-proof-45.json)、候选[86checks](../tests/.runtime/admin-forms-candidate/run-1791614667582-98061837729b/parent-proof-45.json)通过；差一项为本地资源URL数不同。

实际查看四张截图：根[桌面选中](../tests/.runtime/admin-forms-root/run-1791614617203-15d72411d497/coupon-selected-desktop.png)/[移动清空](../tests/.runtime/admin-forms-root/run-1791614617203-15d72411d497/coupon-cleared-mobile.png)，候选[桌面选中](../tests/.runtime/admin-forms-candidate/run-1791614667582-98061837729b/coupon-selected-desktop.png)/[移动清空](../tests/.runtime/admin-forms-candidate/run-1791614667582-98061837729b/coupon-cleared-mobile.png)。桌面1440x1000与移动390x844均显示真实原表单；移动截图覆盖视口内字段，未据此声称所有滚动区都经过点击。

## 永久关系事务与金额回归

四用例都通过原HTTP Kernel和已安装Dcat Form store/update：成功创建/替换/清空、required无写入、关系INSERT失败后的创建回滚、更新失败后标量与已经分离旧关系回滚。两个故障使用真实SQLite BEFORE INSERT trigger，在goods3通过原PDO sqliteCreateFunction回调记录必达后RAISE ABORT；确认实际关系INSERT到达故障点，不能只凭任意statusfalse就称事务验证通过。最终主行/关联/商品全字段等于之前状态，原事务层级归0。

审计指出成功用例遗漏金额持久化断言，已补update与clear之后精确2.50检查。SQLite实际返回2.5，PHPUnit9对双字符串2.50/2.5比较失败，而新版比较数值相等；最终以原bcmath bccomp(...,2)核对金额，不依赖测试运行器的字符串比较差异，也不使用浮点换算。修后每树4tests/35assertions，Linux8.5也独立聚焦同四用例通过。

| 实际运行 | 本轮范围 | 结果 | 实际运行器耗时 |
| --- | --- | --- | --- |
| Windows PHP7.4旧根 | 完整Unit/Feature | 2305 tests /18728 assertions | 25.377s |
| Windows PHP8.5候选 | 完整当前核心XML | 2327 tests /18297 assertions | 43.182s |
| Windows PHP8.5候选 | 最终精确金额断言四Coupon聚焦 | 4 tests /35 assertions | 0.946s |
| Linux PHP8.5候选 | 最终四Coupon聚焦 | 4 tests /35 assertions | 7.673s |

完整JUnit：[旧根最终](../tests/.runtime/forms45/full-root-final.xml)、[候选Windows完整](../tests/.runtime/forms45/full-candidate-windows-audited.xml)；候选最终金额断言的[Windows聚焦](../tests/.runtime/forms45/focused-candidate-audited-final.xml)及[Linux聚焦](../tests/.runtime/forms45/focused-candidate-linux-final.xml)。候选完整通过后仅将金额断言统一为bcmath，重跑受影响四例，其他用例和应用源未改，未重复无关全套。第43轮Linux全套2323/18262保留原证据，本轮不声称Linux完整2327重跑。

[发现身份证明](../tests/.runtime/forms45/discovery-proof.json)确认原2301/2323身份逐项保留，仅新增四Coupon，无缺失/重复/错误/失败/skip。原Injected queue failure和notification failure仍是既有故障控制，非本轮异常。

## 隔离与重放

--forms只接受固定回环8202，使用独立admin-forms-root/candidate路径、SQLite/三商品/原fixture_marker、文件session/cache和compiledviews、专属cookie；与extended/status-mail/deletion互斥，原模式默认语义保留。初始化只在原精确marker或新空DB中创建合成数据；不复用旧核心或扩展报告，浏览器每次产生新的run目录保留失败证据。支付、通知、验证码关闭、mail array，不触发购买或商户/SMTP操作。

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini tests/admin-fixture.php --init-isolated-admin --forms
$env:DUJIAOKA_ADMIN_TREE='root'
$env:DUJIAOKA_ADMIN_FORMS='1'
$env:DUJIAOKA_ADMIN_EXTENDED='0'
$env:DUJIAOKA_ADMIN_STATUS_MAIL='0'
$env:DUJIAOKA_ADMIN_DELETION='0'
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini -S 127.0.0.1:8202 -t public tests/admin-fixture.php
```

另一个终端在同一工作区：

```powershell
node tests/browser-admin-coupon.cjs --php tests/.runtime/php74/php.exe --php-ini tests/.runtime/php74/php.ini
```

停止旧服务并确认8202释放后，候选初始化追加--candidate，DUJIAOKA_ADMIN_TREE设candidate，PHP使用tests/.runtime/php85/php.exe及tests/.runtime/php85-fixture.ini；浏览器追加--candidate和明确相同PHP/INI。重新运行先初始化干净本夹具；不要在持有现有Coupon数据时强行继续创建。原核心8198或其他模式需显式DUJIAOKA_ADMIN_FORMS=0，避免继承终端的forms开关。

原PHP7.4或8.5使用各自PHPUnit命令执行本轮永久测试。候选PHPUnit13设置failOnAllIssues，本轮去除首次误用--do-not-cache-result废弃参数；根PHPUnit9保留其原选项。

早期夹具使用不存在的Admin::userModel、把原校验422误期望200、生成浏览器转义错误、未读取实载Dcat.token、重复点击已开Select2菜单关闭等失败均保留后按原配置/DOM修正；这不是应用缺陷证明。独立父证明先用旧PDO读候选DB进行strict原生类型比较而失败；最终每树使用对应PHP原PDO、不加载框架，比对所有原生字段类型和值通过。两个服务器已按自有job停止并收齐终态，8202实际无listener。

[1225原应用/config/schema/routes/resource输入SHA](../tests/.runtime/forms45/protected-source-proof.json)与第44轮原sourcecopy保持；根112/候选129两锁SHA相同，未改vendor。原根依赖仍Laravel6，整个目标继续active。浏览器在开发隔离Kernel夹具中提供匹配vendor资源，本轮不代替第44[独立no-dev发布](production-release-compatibility.md)或OCI/Engine/systemcron/rootdrop/实际部署schema；SQLite不证明此表单的MySQL锁竞争，未穷举所有后台保存和第三方组件操作。
