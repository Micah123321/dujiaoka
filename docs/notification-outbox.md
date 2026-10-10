# 付款通知持久补偿

商品 API 回调通过 `API_HOOK_OUTBOX=true` 启用持久投递记录；自动发货用户邮件和人工订单管理邮件通过独立的 `PAYMENT_MAIL_OUTBOX=true` 启用。这两个开关与新增人工状态开关均默认关闭，旧店铺无需建表即可继续旧路径。只有付款时已配置回调的商品才创建API记录，只有待处理/已完成付款订单才创建付款邮件记录；既有已付款订单不会自动补建。人工订单待处理/完成/失败客户邮件现在可通过独立 `MANUAL_STATUS_MAIL_OUTBOX=true` 接入，见[状态转换补偿与第37轮验收](order-status-mail-outbox.md)；Server酱、Telegram、Bark和企业微信仍沿用原路径。

## 行为

付款事务把订单、库存变化与各启用通道的 `notification_outbox` 记录一起提交；事务失败时全部回滚。原 `order_id + channel` 唯一键与付款幂等分支阻止重复付款回调增加意图；安装状态邮件迁移后键为三列，旧通道 `event_key` 为空串，保留付款唯一语义。提交后优先发布包装任务，再执行其他通知，其他通知的故障不会删除已提交记录。

API记录只保存订单ID、通道、次数、时间和错误类别，不复制邮箱、卡密、回调URL或凭据。执行时仍读取最新订单内容和商品回调配置，沿用旧队列任务的数据语义；这不是冻结HTTP快照。商品删除或回调被清空会记录失败，修复配置后再重试。订单删除也会进入失败处理。

付款邮件在事务中固定收件人和模板替换数据（包含订单内容/卡密），使用Laravel应用密钥加密后存入新增的payload列，不保存SMTP密码。包装任务仍只带ID和随机标记；发送时解密并读取当时的邮件模板和最新SMTP配置。后续修改/删除订单或商品不会改变已记录的收件人和内容，模板缺失或密文损坏则记录失败，模板修复后可以恢复。不要在待处理或失败邮件记录尚存时直接轮换/丢失APP_KEY；备份需保留与密文对应的密钥。邮件被配置驱动接受后同次状态更新会清空payload，失败记录保留密文以便重试。

付款邮件在Swift路径检查接受收件人数和失败列表；Symfony路径检查SentMessage及非空收件信封。取消、空结果/零接受、空信封或传输异常均不写delivered_at，保留加密快照供重试。双框架配置刷新、SMTP握手拒收和恢复证据见[邮件运行兼容](mail-runtime.md)。此状态仅代表配置驱动接受；SMTP接受不证明最终收件箱到达，array/log驱动也按自身语义报告接受。原MailSend任务默认保持旧行为，新严格检查只用于邮件补偿。

发布时建立300秒租约。未执行任务重新发布时复用标记，允许排队超过租约的旧副本继续执行；任务开始时原子轮换标记，其他副本跳过。运行中的任务失联后，过期租约允许重新发布。发布和发送均在事务提交后，只有记录/领取使用短数据库事务。worker的60秒超时应实际生效并小于租约；需在真实Linux/MySQL部署验证。

队列发布失败延后30秒再试，不增加发送次数。执行失败按60、120、240、480秒退避，最多5次，最后记录 `failed_at` 并停止自动投递。外层任务只有一次队列尝试；恢复由记录和定时补偿负责。命令的 `Published` 只表示任务交给队列，不表示HTTP或邮件已被接受；执行失败记录在outbox，而不是依赖failed_jobs。

回调接收方需按 `order_sn` 幂等处理：远端成功后响应或本地成功标记丢失，重试可能重复发送。邮件服务器接受后、本地状态写入前失联，也可能发送重复邮件。租约不保证跨系统严格一次投递。

## 启用步骤

旧安装只导入安装SQL，不自动运行Laravel迁移。新装和旧店铺都应显式执行所需迁移文件，不重导入安装SQL。第一份仅新增outbox表，首次使用迁移命令时也会初始化Laravel迁移记录表；仅API回调模式不需要第二份payload迁移。

```sh
php artisan migrate --path=database/migrations/2026_01_01_000001_create_notification_outbox_table.php --pretend
php artisan migrate --path=database/migrations/2026_01_01_000001_create_notification_outbox_table.php --force
```

启用付款邮件还需在第一份迁移成功后执行新增payload列的迁移；已开启API回调的店铺只需执行第二份：

```sh
php artisan migrate --path=database/migrations/2026_01_01_000002_add_payload_to_notification_outbox.php --pretend
php artisan migrate --path=database/migrations/2026_01_01_000002_add_payload_to_notification_outbox.php --force
```

按需要设置 `API_HOOK_OUTBOX=true` 和/或 `PAYMENT_MAIL_OUTBOX=true`，按现有部署流程刷新配置并重启worker。数据库/Redis持久队列和每分钟 `php artisan schedule:run` 应正常运行；任一通知开关启用后（包括独立状态邮件开关），Kernel才添加 `notifications:dispatch` 定时任务。补扫和人工重试只选择仍启用的通道。手动补扫使用：

```sh
php artisan notifications:dispatch --limit=100
```

启用后需要写入的通道缺少表/列时，付款事务会失败回滚，不静默丢掉投递意图。本次仅在隔离SQLite中验证前向迁移与队列测试，没有启用真实店铺或修改真实数据库。新增列的SQLite删列回滚受现有Laravel6/DBAL3组合限制，本次未验证；恢复旧模式优先关闭开关并保留表/列，不对尚有任务的表执行回滚。

## 监控与恢复

对 `failed_at IS NOT NULL` 的记录以及长时间未完成的记录设置监控。错误只存类别，不保存原异常消息或链；诊断时检查队列、商品配置和接收方按订单号记录的结果。以下查询不读取通知正文：

```sql
SELECT id, order_id, attempts, available_at, reserved_until, delivered_at, failed_at, last_error
FROM notification_outbox
WHERE delivered_at IS NULL
ORDER BY id;
```

修复原因并核对接收方记录或邮件是否已被接受后，按outbox记录ID重置一条耗尽记录；邮件人工重试同样可能产生重复通知：

```sh
php artisan notifications:retry ID
```

该命令只重置失败记录，完成记录和仍在重试的记录不重置；下一次补扫再发布。`queue:retry`不会重置outbox状态。API/付款开关关闭会停止新记录和补扫，已排队旧通道包装任务仍可处理；新状态通道关闭还暂停其已排队任务的领取，重新启用后补扫恢复；切换模式前应处理遗留记录/任务，避免旧任务与新直接回调重叠。恢复旧模式只需关闭开关，不应在仍有待投递记录或任务时删除表。

## 真实持久队列进程补验

[第35轮独立PHP进程验收](queue-lifecycle.md)在正式PHP7.4/Laravel6与PHP8.5/Laravel13各完成五场景、24子进程。真实数据库queue处理外层DeliverNotification、API/array邮件、同token副本、503退避恢复，原AppKernel两次schedule实际子进程补扫，queue:restart排空及运行失联/lease恢复均通过。九条outbox完成、邮件密文全清、queue为空，主动失联原job按tries1留一MaxAttemptsExceeded记录，恢复依赖outbox而非queue:retry；报告与每个进程配置逐项proof保留。SQLite文件证明这些进程顺序与生命周期，MySQL锁竞争使用独立对应报告；本轮未验证PCNTL timeout、容器托管、远端已接受但本地成功落库失联或跨升级旧队列全量消费。

## 验证范围

NotificationOutboxTest使用隔离SQLite、实际Laravel同步队列和Guzzle MockHandler，覆盖入队失败恢复、提交后执行、付款与意图回滚、重复付款/任务、慢队列、运行失联、商品删除或撤销回调、尝试上限、人工重试、关闭开关免迁移及单文件迁移重复执行。其他通知在多数回调夹具中被隔离，另有原通知路径不再重复发布ApiHook的测试。PaymentMailOutboxTest另覆盖付款邮件提交后发送、买家/管理员模板、加密快照、模板缺失修复、取消/零接受/异常恢复、密文损坏、订单商品后续编辑、邮件终态人工重试、API/邮件独立开关、邮件关闭免迁移及前向新增列保留既有记录。最终邮件夹具使用永久array配置并在发送事件中断言隔离驱动，避免模拟时钟让配置过期；初次夹具发现此问题后已修复。SQLite证明顺序行为，不证明MySQL多进程锁竞争；未访问实际回调服务，未运行生产worker或容器，真实SMTP接受及最终收件箱到达尚未验证。另有[独立MySQL并发验收](mysql-concurrency.md)：MySQL8.4.11/InnoDB下实际锁等待、重复支付单一意图、多个publisher及同token worker竞争已通过；验证范围仅限该隔离版本和夹具，不代表生产持久队列或所有数据库版本。
