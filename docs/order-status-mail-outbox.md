# 人工订单状态邮件持久补偿

`MANUAL_STATUS_MAIL_OUTBOX=true` 启用人工订单客户的待处理、完成和失败状态邮件补偿；默认关闭，独立于 API 回调与付款买家/管理邮件开关。关闭时沿用旧状态邮件路径，旧数据库无需增加表或列。

启用后，真实订单 SQL 状态转换和加密投递意图一起提交。仅修改信息、标题或重复设置同一状态不产生新邮件；两份陈旧订单视图请求同一状态只记录一次，完成→失败→再次完成会记录不同事件。后台编辑和网格下拉框使用同一模型路径。数据库意图写入失败会回滚状态；模板缺失或传输失败则在提交后保留投递记录，修复后重试。

订单模型在 `performUpdate` 内加行锁，核对实际保存前后的数据库状态，范围包括 SQL 与同步 `updated` 监听器；后续 `saved`、touch 和直接 Query Builder 批量更新属于不同边界。状态邮件只使用该同步保存窗口产生的事件；回滚事件或已经结束的事件重放不会补建新的意图。消费服务使用应用默认数据库连接，启用此功能时订单在非默认连接上的保存会在写入前明确失败。

付款服务仍通过 `withoutEvents` 保存。人工付款待处理客户邮件在付款事务内单独创建意图，管理邮件由原付款开关或原直接路径处理；付款提交后的显式业务事件不重复创建状态意图。后台保存仅记录意图，随后由每分钟 `notifications:dispatch` 发布；不在后台数据库事务内发送或发布邮件。

## 数据与迁移

需要原 [outbox 表迁移](../database/migrations/2026_01_01_000001_create_notification_outbox_table.php)、[加密 payload 迁移](../database/migrations/2026_01_01_000002_add_payload_to_notification_outbox.php)，以及本次 [event_key 迁移](../database/migrations/2026_01_01_000008_add_event_key_to_notification_outbox.php)。新增 `event_key varchar(64) default ''`，唯一键变为 `order_id + channel + event_key`。旧 API/付款记录的 key 保持空串，保留每订单每旧通道唯一的契约；状态通道为 `order-status-mail`，每次转换有独立随机 key。先建立新唯一键，再删除旧键，迁移保留既有记录。

```sh
php artisan migrate --path=database/migrations/2026_01_01_000008_add_event_key_to_notification_outbox.php --pretend
php artisan migrate --path=database/migrations/2026_01_01_000008_add_event_key_to_notification_outbox.php --force
```

此处为部署重放命令，本轮不会对实际店铺执行。完成三份迁移后再启用开关、刷新配置、重启 worker，并确保持久队列与 `schedule:run` 托管正常。存在状态记录时，迁移 down 会保留数据并停止；Laravel6/DBAL3 的 SQLite 删列 down 同样明确停止。恢复旧模式应关闭开关并保留任务和表，而不是直接删列。

客户邮箱、模板 token 与原替换数据在转换时冻结后加密，包括订单内容；后续订单编辑不改变已记录的邮件。包装任务仍只带 ID 和随机领取标记，错误仅存类别。不同转换独立重试，不保证邮件接受顺序；本轮首封完成邮件在后续三封之后补发，仍保持最初内容快照。发送时使用当时的模板和最新邮件配置，模板缺失可以修复后重试。传输接受后清空密文，失败记录保留密文供恢复；应用密钥轮换与备份边界沿用 [原邮件补偿说明](notification-outbox.md)。传输接受代表配置驱动接受，不代表最终收件箱到达。

状态开关关闭会暂停新记录、补扫、人工重试和已经排队的状态任务领取；API/付款旧任务继续原领取契约。暂停后需保留未完成意图，重新启用后由补扫恢复，不应把已排队包装任务的成功退出当作邮件送达。

## 隔离后台与原生队列重放

[后台路由夹具](../tests/admin-fixture.php) 的 `--status-mail` 模式独立使用 8200 和 `tests/.runtime/admin-status-mail-{root,candidate}`，不覆盖原 8198/8199 夹具。它派生安装 SQL 字段，使用自建账号、订单与三份模板，永久配置 array 邮件传输并在发送事件中断言事务层级为零；不存在外部 SMTP 投递。

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini tests/admin-fixture.php --init-isolated-admin --status-mail
$env:DUJIAOKA_ADMIN_TREE='root'
$env:DUJIAOKA_ADMIN_EXTENDED='0'
$env:DUJIAOKA_ADMIN_DELETION = '0'
$env:DUJIAOKA_ADMIN_STATUS_MAIL='1'
& tests/.runtime/php74/php.exe -c tests/.runtime/php74/php.ini -S 127.0.0.1:8200 tests/admin-fixture.php
```

服务运行后在另一个终端执行 [浏览器验收](../tests/browser-admin-status-mail.cjs)：

```powershell
node tests/browser-admin-status-mail.cjs
```

候选先停止旧根服务，再使用 PHP8.5 INI、初始化及浏览器均追加 `--candidate`，服务环境树设为 `candidate`。脚本通过真实编辑表单和网格 select 修改状态，检查 GET 角色拒写、CSRF 与 DELETE 拒绝，随后通过原 Console Kernel 发布到数据库 queue，运行五个独立 `queue:work --once` PHP 进程。第一封注入 array 传输异常，验证退避前不发布；仅在标记夹具内模拟 `available_at` 到期再补扫，保留该模拟记录，不用等待一分钟代替断言。最终验证四份冻结邮件、四个意图、事务层级零、密文清空和空队列。

真实应用回归使用 [状态邮件测试](../tests/Feature/OrderStatusMailOutboxTest.php)，原付款与 API 通道仍使用既有测试。新增 [MySQL 独立验证](../tests/Integration/OrderStatusMailMySql/run.php) 只使用自有固定实例 `127.0.0.1:13316` 的 `sm_` 前缀，核对迁移保留旧行与唯一键，以及两份真实陈旧模型在 InnoDB LOCK WAIT 后同状态单意图、不同状态双意图；不覆盖原 `mc_` 数据。实际结果见下方第37轮证据。

MySQL实例按[既有自有实例说明](mysql-concurrency.md)启动并确认ready，两个树顺序执行下列命令。实例须是原固定marker和项目数据目录，脚本只重建自己的两张sm_表；不重新初始化实例：

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/mysql-fixture-php.ini tests/Integration/OrderStatusMailMySql/run.php --enable-isolated-mysql
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Integration/OrderStatusMailMySql/run.php --enable-isolated-mysql --candidate
```

## 第37轮证据

| 验证 | PHP7.4 / Laravel6 | PHP8.5 / Laravel13 |
| --- | --- | --- |
| 完整 PHPUnit | 2229 / 18257，零失败/错误/跳过 | 2251 / 17826，严格零失败/错误/跳过 |
| 三通知聚焦 | 81 / 584 | 81 / 472 |
| 新增状态邮件用例 | 46；原2183身份全部保留 | 46；原2205身份全部保留 |
| 真实后台 | 9页面 / 9操作组 / 30本地资源 | 9页面 / 9操作组 / 29本地资源 |
| 原生数据库 queue | 5个worker进程 / 10事件 / 4封array接受 | 5个worker进程 / 10事件 / 4封array接受 |
| 最终意图 / 队列 | 4完成，attempts 2/1/1/1，密文全清；queue0 / failed0 | 同左 |
| MySQL8.4.11 / RR | 2组 / 4worker，每组2人LOCK WAIT | 同左 |

修正前有实际失败证据：初版三项旧路径缺陷两树各3失败；审计组合用例复现回滚后重放幽灵意图和独立模型嵌套同状态双记录，另复现非默认连接无消费路径。父补同一对象在updating/updated内重入丢意图，两树均2失败，另复现旧回滚事件借用同一对象后续保存窗口，改为事件key绑定所属保存并按栈恢复。修复后永久保留46项状态回归。

最终完整报告：[root](../tests/.runtime/phpunit-root-final-37.xml)、[candidate](../tests/.runtime/laravel12/laravel13-final-37.xml)；聚焦：[root](../tests/.runtime/order-status-final-after-root-37.xml)、[candidate](../tests/.runtime/order-status-final-after-candidate-37.xml)。真实浏览器报告：[root](../tests/.runtime/admin-status-mail-root/browser-summary-37.json)、[candidate](../tests/.runtime/admin-status-mail-candidate/browser-summary-37.json)；独立CLI终态与身份对照证明：[root](../tests/.runtime/admin-status-mail-root/parent-proof-37.json)、[candidate](../tests/.runtime/admin-status-mail-candidate/parent-proof-37.json)。两树浏览器errors/unexpectedHTTP/external均为空，真实最终截图已查看。

MySQL原始报告：[root](../tests/.runtime/admin-status-mysql-root-37-e2dfc489/summary.json)、[candidate](../tests/.runtime/admin-status-mysql-candidate-37-051fb693/summary.json)。两份已逐项确认：新迁移保留旧payload与空event_key/旧通道唯一；同状态各一意图，不同状态各两意图；释放前和继续持锁500ms后两worker仍全部LOCK WAIT，查询观察间隔150ms，状态记录存在时down停止且保留数据。服务只使用已保存自有实例，新sm_表独立于既有mc_，未重建数据目录。

本轮全部8200服务已停止，13316 MySQL正常shutdown。没有改根/候选依赖锁、vendor、发布资源、真实数据库或主机worker/cron，没有外部通知或部署。Linux PCNTL、container/Supervisor、部署版本/表结构、其他后台批操作与永久删除及最终切根仍待验收；整体目标保持进行中。

