# MySQL 并发交易验收

常规 PHPUnit 使用SQLite内存数据库，验证顺序事务行为；本轮另准备MySQL8.4独立实例与多进程验收，验证InnoDB锁竞争。测试库固定为 `dujiaoka_concurrency_fixture`、主机 `127.0.0.1`、端口 `13316`，不读取店铺环境文件。实例启动不安装Windows服务、不修改系统PHP、不绑定公网接口。

## 隔离实例

本机使用[MySQL官方ZIP发行包](https://dev.mysql.com/downloads/mysql/8.4.html)：`mysql-8.4.11-winx64.zip`，官网下载页列出的MD5为 `2e833921898a9a030ea6bfe81bd811bc`。程序和数据均放在被Git忽略的 `tests/.runtime` 下，普通PHPUnit不启动此服务。PHP比较运行时为现有7.4.33；独立配置 `tests/.runtime/mysql-fixture-php.ini` 启用pdo_mysql，worker继承同一配置。仅向协调进程传 `-d` 不会自动传给子进程。下载包SHA256实测为 `a492371d687d2bab088b0062581144a0044b8964baefdf4faa579292b423d25c`。

初始化遵循[MySQL官方数据目录初始化说明](https://dev.mysql.com/doc/refman/8.4/en/data-directory-initialization.html)，指定 `--no-defaults` 以避免继承主机配置，使用全新的数据目录。进程配置固定loopback、端口13316并关闭mysqlx额外监听接口。测试账户 `dujiaoka_fixture` 仅具有该固定夹具库权限，实例仅用于本地临时验证。协调进程另使用这个新实例的root账户只读查询information_schema.innodb_trx，核对各worker连接确实进入LOCK WAIT；读取前核对标记及实际数据目录。它不读取其他主机账户或应用凭据。

标记表 `fixture_marker` 必须含 `dujiaoka-isolated-concurrency-v1`，协调脚本及worker都应核实。没有标记或端口/主机不同的连接应停止，不创建或删除夹具。脚本不删除数据库；重跑只重建它使用的夹具表，保留标记表。

## 目标场景

- 多个独立PHP进程同时对同一订单提交相同支付交易号，核对状态、卡密消费、销量及通知意图只改变一次。
- 两单竞争一张卡，核对一单完成、另一单异常，避免重复分配卡密。
- 支付与到期竞争同一订单，核对券只返回至多一次，过期胜出后付款转为人工处理且不会重新消费返回券。
- 多个publisher和worker竞争同一通知意图，核对租约领取和重复任务抑制。通知HTTP由MockHandler模拟，不连接实际接收方。
- 两个进程先在订单锁阶段等待，再各自在事务外取得不同模拟报价，通过取价屏障后竞争保存；核对同一订单/渠道仅有一行冻结报价，两个进程都返回其金额。
- 两个进程竞争一个Stripe订单，在短事务创建/复用唯一绑定，再通过API请求屏障；核对请求的幂等键及完整参数相同、事务层数为0、最终仅一个本地/远端ID绑定。远端API为本地替身。
- 两个进程在渠道锁上竞争保存同一签名后规范化事件，仅一条收件；另两个进程在对账状态锁上竞争领取同一90秒租约，赢家在事务外模拟GET并等待另一进程退出，最终仅一次真实发货和销量变动。此租约组暂缓处理事件以单独验证后台声明；原生签名到发货流程另由PHPUnit覆盖。
- 两个PayPal创建请求在事务外经屏障要求相同参数/key，唯一Order绑定；两capture进程争同90s租约仅一个POST替身，赢家等另一进程退出再返回实收，最终一次原卡密/销量事务。
- 同订单已绑定PayPal时，Stripe选择进程必须停止、原渠道选择保持；另两个独立订单分别Stripe/PayPal在共享检查后屏障强制交错插入，证明空索引检查不会使跨供应商互相持间隙锁死锁。该组修复前已真实复现死锁，修复后重放通过。

协调进程先锁住目标行，所有worker就绪后释放文件屏障；用information_schema.innodb_trx确认对应连接均处于LOCK WAIT，再持续500毫秒观察并释放行锁。每组保存连接ID、实际等待数和独立worker分类结果。Ready/gate/result/锁等待都有超时，失败非零退出并清理子进程；协调锁防止两个验收进程同时重建夹具。

只重建 `mc_goods`、`mc_orders`、`mc_carmis`、`mc_coupons`、`mc_notification_outbox`、`mc_queue_probe`、`mc_send_probe`、`mc_pays`、`mc_payment_quotes`、`mc_stripe_payment_intents`、`mc_stripe_webhook_events`、`mc_stripe_reconciliations`、`mc_paypal_orders`、`mc_paypal_webhook_events`、`mc_paypal_reconciliations` 十五张夹具表。真实生产交易服务、库存/券逻辑和ApiHook代码执行；商品详情、队列发布和外部HTTP使用本地替身。普通邮件/状态事件在并发夹具中被隔离。用本机多进程结果证明指定场景，不外推为所有数据库版本、索引、故障时序或生产队列已验收；邮件和HTTP真实网络投递不在此测试中。

## 运行与结果

数据目录仅首次初始化。当前工作区已保留忽略目录内的运行时、数据库及配置，后续重跑从启动实例开始；不要重复对已有目录执行初始化。实例配置中basedir/datadir均指向项目临时目录，端口13316、bind-address=127.0.0.1、mysqlx=OFF。

```powershell
& tests/.runtime/mysql84/mysql-8.4.11-winx64/bin/mysqld.exe --defaults-file=E:/code/php/dujiaoka/tests/.runtime/mysql-fixture.ini --console
```

历史PHP7.4验收默认引导`tests/Compatibility/Laravel6/bootstrap.php`及独立vendor，现代候选仍由--candidate选择`tests/Compatibility/Laravel12/vendor`；两者均强制MANUAL_STATUS_MAIL_OUTBOX=false。先执行`composer install --working-dir=tests/Compatibility/Laravel6 --no-scripts --no-plugins`（Composer2.10使用完整PHP8.5夹具INI；清单固定platform.php=7.4.33），不会安装或覆盖正式根vendor。

实例启动后，在独立命令执行器运行初始化检查及验收。初始化脚本先核对端口、回环绑定和数据目录，再创建测试库/账户；已有正确标记时只验证，不重建数据库。

```powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/mysql-fixture-php.ini tests/mysql-fixture-bootstrap.php --initialize-fixture
& tests/.runtime/php74/php.exe -c tests/.runtime/mysql-fixture-php.ini tests/Integration/MySqlConcurrency/run.php --enable-isolated-mysql
```

此前基线为MySQL8.4.11、REPEATABLE-READ，十五张夹具表全为InnoDB。原18组竞争、49个独立worker及七份前向迁移全部通过，各组实际数据库锁等待均被确认。报价竞争中两组不同报价均在事务外取得，最终仅一行快照，两个worker都返回赢家1.43 USD。新增Intent竞争仅一行绑定、两份模拟API创建请求参数/键相同、最终provider_id相同。新收件竞争仅一条记录，对账竞争仅一次GET替身调用且一笔真实卡密/销量变动。PayPal创建/捕获场景一行绑定、捕获一份POST与一笔真实卡密/销量，另独立订单交错增加两行各供应商绑定；新增PayPal收件并发仅一条规范化事件；持久对账租约竞争仅一份Order GET替身并独立Capture核对，再真实发货/销量一次。报告的row/sales统计指各指定场景，不是最终整个夹具库总数。重复付款仅增加一次销量和一条通知意图；丢失发布的补偿场景由四个publisher竞争，最终发布一次；四个同token worker最终模拟HTTP一次、attempts=1并写成功状态。两单一张卡仅一单得到该卡；支付/过期/退券竞争及遗留晚支付场景保持券返回与卡密消费约束。

原18组脱敏报告位于 `tests/.runtime/mysql-concurrency/run-20261009-193209-e5dfb9e4/summary.json`，同目录下每组保存lock-observed.json和worker分类结果。PayPal创建/选择/捕获及独立跨供应商插入均保存实际LOCK WAIT和500毫秒持续等待；请求记录证明网络在事务层数0执行，对账赢家保持租约到另一进程退出。临时任务文件含夹具token，均放在Git忽略的运行目录，标准输出不打印卡密/邮箱/SQL绑定或token。本轮现代PayPal协议/共享付款锁变更的常规PHP7.4全量399 tests/4049 assertions已独立通过。

## 第三十四轮：Laravel13实际重放

当前候选已升级Laravel13.35.0/Dcat X2.0.4，完整独立PHP8.5.11 INI与实际安装树重放[当前MySQL summary](../tests/.runtime/mysql-concurrency/run-20261010-024647-3e7425d9/summary.json)：MySQL8.4.11/RR、36groups/85workers、七份前向迁移及十五表全InnoDB。36份锁报告全部逐项核对，合计85worker，初次及继续持锁500ms后的等待数均全员，观察间隔150ms，见[精确核对](../tests/.runtime/laravel12/laravel13-mysql-proof-34.json)。等待ready后通过mysqladmin ping才初始化检查，无数据目录重建。通过mysqladmin正常shutdown并确认13316、8198无监听。[当前框架整体验收](laravel13-compatibility.md)提供版本和重放边界。

## 第三十三轮：PHP8.5/Laravel12实际重放（历史）

现有候选锁升级PHP最低^8.4/PHPUnit13后，以官方SHA256校验便携PHP8.5.11及完整独立INI实际重放当前Laravel12.69.3候选。原实例使用既有marker，未重做数据目录初始化；首次bootstrap早于数据库ready产生PDO2002，尚未进入竞争。观察ready和mysqladmin ping后重跑成功。

最终[当前PHP8.5 summary](../tests/.runtime/mysql-concurrency/run-20261010-023122-33d558eb/summary.json)：MySQL8.4.11/RR、36groups/85workers、七份前向迁移、十五InnoDB表。36份lock-observed逐项核对，worker合计85；每组释放前与持锁500ms后等待数均等于worker数，观察间隔150ms，见[精确核对](../tests/.runtime/laravel12/php85-mysql-proof-33.json)。共同回执guard与旧v1预留/GET-only/approved恢复均通过，业务服务及实际库存/销量语义与第30轮范围一致。通过mysqladmin正常shutdown并确认13316无监听。PHP8.5常规套件与真实后台另见[维护版平台](php-runtime-compatibility.md)。

## 第三十轮：双框架回执与旧 v1 预留（历史）

根 PHP7.4.33/Laravel6.20.42 与候选 PHP8.2.31/Laravel12.69.3 各通过36组/85个独立worker，仍使用上述十五张夹具表及七份真实前向迁移。两套依赖顺序运行，共72组/170个worker；每组所有连接都必须先被观察为LOCK WAIT，并在继续持锁500ms后再次确认相同等待数。普通全量PHPUnit保持此前根2100/16764、候选2106/16306证据，本轮未修改应用代码或依赖。

新增15组直接执行共同receiptGuard与completedOrder：worker在ready之前读取订单/渠道快照；八种真实变更在等待锁确认后提交，包括pay_check、route、三项merchant字段、订单金额、pay_id与渠道软删除，均停止两份回执且库存/销量不变。其余场景验证关闭渠道已付恢复、过期转人工且库存保留、Stripe/PayPal已有绑定排除、同交易仅履约一次、不同交易仅一个成功、字符串0保持。不同交易号的最终数据库引用必须精确对应唯一成功worker输入。

新增3组调用实际旧PaypalPayController、Guzzle协议客户端和完整frozenUsd；只统计报价读取次数，不替换读取或事务行为。第二道GET屏障允许初步报价完成后再持订单锁，使等待发生在真实执行预留或履约事务上。unknown组独立PDO证明预留标记已提交，唯一execute等待另一worker查询created并退出后返回503；retry组仍created只GET，重新结账不会另建对象；approved组用completed Sale恢复真实卡密/销量事务。三阶段共六次业务GET、一次execute，最终一张卡、一次销量；所有协议I/O在事务层数0。

首次旧对账组及v1组未观察到全部等待。诊断保留了部分LOCK WAIT，释放后worker业务完成；核实[实际MySQL8.4.11源码](https://github.com/mysql/mysql-server/blob/mysql-8.4.11/storage/innobase/trx/trx0i_s.cc#L692)发现INNODB_TRX缓存要求距上次读取超过100ms，原20ms连续查询使旧快照持续复用。专门将数据库观察改为150ms，普通文件屏障仍20ms，并增加500ms后的第二次等待确认及无SQL文本的失败状态记录。修复后双框架36组通过。独立审查另发现不同引用Oracle允许空串/败者引用，已严格关联唯一成功worker，随后双36组重新通过；这是验收断言修复，未宣称生产出现相同错误。

最终报告：[根运行时](../tests/.runtime/mysql-concurrency/run-20261010-010910-699d60af/summary.json)、[候选运行时](../tests/.runtime/mysql-concurrency/run-20261010-010959-98521a79/summary.json)。旧中间通过和失败证据保留，最新报告覆盖断言修复；各组lock-observed.json包含前后等待数、150ms间隔和连接ID。

~~~powershell
& tests/.runtime/php74/php.exe -c tests/.runtime/mysql-fixture-php.ini tests/Integration/MySqlConcurrency/run.php --enable-isolated-mysql
& tests/.runtime/php85/php.exe -c tests/.runtime/php85-fixture.ini tests/Integration/MySqlConcurrency/run.php --enable-isolated-mysql --candidate
~~~

当前候选ini为忽略目录的php85-fixture.ini，extension_dir指向便携PHP8.5.11的ext，启用mbstring、pdo_sqlite、pdo_mysql、openssl、fileinfo、gd、curl、zip，timezone=Asia/Shanghai；bcmath由该PHP编译内置。旧PHP8.2的ini及第30轮报告保留历史边界。安装的GD/zip必须完整启用以满足候选Composer平台检查。协调器及worker继承完整ini，单独-d参数不会传给子进程；没有修改系统配置。--candidate仅选择候选autoload，原CLI显式标记、固定库/端口/账户、数据目录和协调锁约束继续执行。

该验收使用独立Container/真实控制器与交易服务，HTTP内核/签名由既有原生SQLite套件验证；供应商HTTP、通知、商品详情及队列为本地替身。仅证明固定MySQL8.4/夹具结构及指定时序，实际部署数据库、真实商户TLS/结算、生产队列、后台/资产浏览器与正式平台/容器/worker/scheduler仍待验收。

验收后通过本实例的mysqladmin正常停止服务：

```powershell
& tests/.runtime/mysql84/mysql-8.4.11-winx64/bin/mysqladmin.exe --no-defaults --protocol=TCP --host=127.0.0.1 --port=13316 --user=root shutdown
```

关闭后复核13316无监听；运行时和证据保留供重放。本轮未连接店铺数据库、修改生产设置或发送外部通知。
