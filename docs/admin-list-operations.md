# 后台列表批量操作与克隆

列表左侧复选框选择当前页面的记录。选中后，顶部菜单提供批量操作；列表上方显示选中数量与“取消全部选择”。取消选择同时清空表头、行复选框及原生批量请求所用的 ID，不会更改业务数据。筛选、翻页或完成批量更新后选择重新计算。

| 列表 | 批量操作 | 行菜单克隆 |
| --- | --- | --- |
| 商品 | 上架、下架 | 克隆并编辑 |
| 支付渠道 | 启用、停用 | 克隆并编辑 |
| 商品分类 | 启用、停用 | — |
| 优惠码 | 启用、停用 | — |
| 其他原生列表 | 取消全部选择、原有删除/恢复 | 保留已有操作 |

批量更新最多 100 条，逐条核对原有更新权限并在事务内执行。选中记录中任一 ID 不存在、已在回收站、权限缺失、支付配置缺失或保存失败时整批不落盘。回收站保留原有恢复入口，不显示启停或克隆。

商品副本保留价格、介绍、图片引用、批发优惠、买家填写配置和交付方式，默认下架，库存与销量清零。卡密、订单、优惠码关联留在原商品；副本按新商品独立配置和补货。

支付副本保留支付服务与账户配置，默认停用，以唯一的 draft-* 临时标识防止覆盖原渠道。编辑中须选择服务支持的真实付款方式；已占用的固定标识有明确提示。保存真实且未占用的付款标识后，启用仍需通过原生表单的账户验证。批量和行内开关同样禁止启用草稿。回调地址由新渠道编号生成，第三方 Webhook ID 保留原值并需在服务端按实际账户核对。

主题由 [admin-theme.php](/E:/code/php/dujiaoka/config/admin-theme.php) 集中配置；服务端原生组件颜色、JavaScript 调色板与 CSS 变量一致。新增按钮和图标、开关开闭态、checkbox、分页、菜单、Select2、日期选择、确认弹窗、上传按钮、Vue 页面和富文本编辑内容共同跟随明暗主题。

验证入口：[AdminBulkActionTest.php](/E:/code/php/dujiaoka/tests/Feature/AdminBulkActionTest.php)、[grid-selection.test.ts](/E:/code/php/dujiaoka/frontend/src/admin/grid-selection.test.ts)、[browser-admin-bulk.cjs](/E:/code/php/dujiaoka/tests/browser-admin-bulk.cjs)、[browser-admin-theme.cjs](/E:/code/php/dujiaoka/tests/browser-admin-theme.cjs)。浏览器脚本使用端口 8202 的隔离 admin-forms 数据库与合成支付账户，不发起支付或邮件。先用 [admin-list-fixture.php](/E:/code/php/dujiaoka/tests/admin-list-fixture.php) 初始化有标记的隔离表单数据库并播入 31 条合成渠道，再运行两个浏览器脚本。初始化脚本仅用于 CLI，沿用测试框架的数据库、缓存、存储隔离检查；验证完成后可用原表单 fixture 重置。
