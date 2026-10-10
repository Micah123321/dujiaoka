# 独立无开发依赖发布的真实交易链

第46轮在独立PHP8.5.11/Laravel13.35.0发布中补齐原Nginx/FPM下单→数据库队列→原worker→原过期补偿命令，终稿[运行报告](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/runtime-summary.json)PASS472checks/56客户端请求/34真实FPM，151.100s；[父独立复核](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/parent-proof-46.json)PASS24503checks。此处counts包含每请求的实际included-file及原复制source逐文件核对，不是24503条不同业务场景。

## 原生产缓存与请求状态

[新stage](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/stage-summary.json)37checks/304.195s通过，实复制原应用及12623 vendor文件。原Composer固定锁100运行包、29开发包缺席，无tests目录；[vendor复制证明](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/vendor-copy-proof.json)treeSHA d98a61f5f8504918e6eb05776fad118df35134b504e60093b73ec3c29d24dedf，与第44原安装100包相同。原package discovery、850后台asset正式发布、config/route/view缓存仍实跑；完整保留第44原32次只读请求并断言在交易前原orders没有更改，随后增加24动态请求。

[显式--transactions](../tests/Integration/ProductionRelease/run.php)只作用于新成功且未启动stage，每次从argv赋true或false，避免先写manifest后终止留下隐式写入模式。[私有环境](../tests/Integration/ProductionRelease/bootstrap.php)在config缓存前选择原database queue及database-uuids失败provider，生产、debugfalse、文件session/cache和privateSQLite。原合成[seed](../tests/Integration/ProductionRelease/seed.php)继续从原SQL建业务字段，仅单独补jobs/failed_jobs队列表；原实际provider为Illuminate Queue DatabaseUuidFailedJobProvider，原app时区Asia/Shanghai。不会调用会重建业务表的整套queue fixture schema。

实际交易通过cURL获取原Vue买页modern-data的CSRF及字段，保留真实session Cookie后提交原/create-order，并读取原bill/状态/浏览器查询。这一轮没有声称Chrome点击或Vue交互截图；已有真实浏览器范围见[第45优惠券](admin-coupon-compatibility.md)和此前主题验收。

- 原缺CSRF419；有效CSRF但人工数量11、无效email、缺失coupon均原错误页200，全九表orders/coupons/pivot/goods/carmis/pays/emailtpls/jobs/failed_jobs无写入。
- [真实AFTER UPDATE ret故障探针](../tests/Integration/ProductionRelease/transaction-probe.php)在coupon递减时RAISE ABORT，原错误内容含特定Injected production coupon failure，证明实际走到coupon UPDATE之后；此前已insert订单随原事务回滚，九表全字段与初始状态一致。故障表trigger随后实际移除。
- 两张自动单各qty1，原金额9.00减券1.00为8.00、券ret2→1→0；人工单qty3总额/实额27.00，无券。各原302 bill跳转及bill现代数据金额一致，原status JSON400000；各一次真实延迟数据库job，attempts0/reservedNULL/default队列、实际available_at与原serialized OrderExpired的SN及created_at+300s期限一致。
- 券耗尽再次提交原错误页200，原订单与jobs没有重复写入。原/browser查询结构准确三个新SN，原两seed订单不在其Cookie历史。

[原交易runner](../tests/Integration/ProductionRelease/transactions.php)留存15份独立PDO query_only快照，每次检查原marker后在只读事务读全字段。全部56个request输入、response字节SHA、唯一ID与Nginxmethod/uri/status逐条匹配；34动态请求也独一FPMmethod/path/status匹配，22请求为原静态资源或/.env边界。每个FPM都实读release/config缓存/database queue/UUID failure driver/AsiaShanghai、App/vendor来源、sole本发布loader、included-file边界及last_php_error=null，开发类未加载。

## 原加密Cookie、worker与一次补偿

[Cookie和provider证明](../tests/Integration/ProductionRelease/transaction-cookie.php)只在本新release Console Kernel启动，使用原App EncryptCookies middleware实际解密已收到的dujiaoka_orders，要求三个SN准确、cipher与plaintext不同；改变cipher字符后原middleware解码为null。查看页面包含SN不是加密证明，原页面结构和实密码校验都各有断言。[实际crypt档案](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/transaction-cookie-proof.json)通过。

原UUID失败provider用含原Str::uuid生成UUID的合成payload真实log，查询该failed_jobs行后原forget回收，原jobs全字段不变；[原provider实际记录](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/transaction-failure-provider.json)保留driver、provider来源、记录及已回收证据。此为失败记录表兼容控制，不声称worker耗尽重试已经实跑。

随后仅把首个真实future job的available_at调至now，serialized payload/private deadline保持原字节，另外两个job所有字段保持。原artisan queue:work database --queue=default --once --sleep=0 --tries=3 --timeout=20实际处理OrderExpired并DONE225.46ms；[worker身份](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/transaction-worker-early.php-proof.json)生产、缓存、App Console Kernel/privateDB/databasequeue/UUID failure driver及sole vendor loader均实际断言。首job删除、新三单仍待付、coupon仍0、failed_jobs空，原deadline提前返回正确。

setup-only年龄控制只调整三个新SN且id>2的created_at，按seed实际Asia/Shanghai转为now-360s；只调整时间，非等待真实六分钟，不修改原任务payload或remaining available_at。原orders:expire第一遍实际输出Expired: 3; failed: 0；新三单status-1，两券单coupon_ret_back1、人工无券flag0，券ret归2。第二遍Expired: 0; failed: 0，全部九表逐字段一致。原卡密仍未售、人工库存仍10——下单未付款时原实现未扣库存，所以这里没有库存“恢复”动作。旧两seed订单全部字段保持。原过期bill错误页与status400001也通过。

两原sweep都强制核对其[第一遍](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/transaction-sweep-one.php-proof.json)/[第二遍](../tests/.runtime/production-release/run-20261010-070530-493d430651cc2c516e57cd6f/transaction-sweep-two.php-proof.json)原CLI身份、raw error与includes边界。保留另外两个原delayed jobs作为重放证据，未再启动后台worker；worker的逾期消费及真实五分钟到期等待各为其他验收范围。

## 隔离、故障保留与重放

首份stage成功后，[首次runtime](../tests/.runtime/production-release/run-20261010-065626-78b1f2c1352b629fb4263a2c/runtime-summary.json)在38实际客户端/16实际FPM之后遇新probe重复引导pr_manifest而exit1；无业务写入。require_once修正后另用新空database/cache、同固定vendor重新stage，不覆盖失败。首失败summary中的native_requests=0是失败时尚未进入正常末尾流量归集；父直接重开raw16行确认实际请求数，不把0当成实际FPM流量。

[首失败父独立71checks](../tests/.runtime/production-release/run-20261010-065626-78b1f2c1352b629fb4263a2c/failure-parent-proof-46.json)确认五份九表snapshot与最终seed一致、11root句柄关闭、13原PID/startticks identity消失、两个端口关闭；原fault child255为真实错误，其他0，不将本轮首次失败记作成功。第二终稿runtime18root全exit0/20owned identities消失/双native listener关闭、cleanup无错误。另[13原路径/exit0 PHP诊断控制](../tests/.runtime/production-release/run-controls-20261010-071047-103fa97e7cf1cb4c4ef8a264/controls-summary.json)通过；两个既有已完成PASS非transaction及FAILstage上新SQL探针实际exit1止于资格检查，原记录保持。

原第44成功包及HTTP/DB记录另行保留。第46[1225原业务配置资源SHA](../tests/.runtime/release46/protected-source-proof.json)与第44sourcecopy同，两锁仍112/129固定图与原SHA，未改App/vendor/core test。复用第45完整旧根2305/18728、Windows候选2327/18297及最终Linux四例4/35，依据[未变化测试源SHA](../tests/.runtime/release46/core-evidence-reuse.json)；本轮实际运行是生产release harness，未再次声称全套重新运行。

重放先用[stage](../tests/Integration/ProductionRelease/stage.php) --from-installed 一个owned成功且同当前锁的stage，得到新的run，再对[run](../tests/Integration/ProductionRelease/run.php)传该唯一新路径及--transactions。Linux PHP/ini/LD_LIBRARY_PATH按[原发布重放](production-release-compatibility.md)，原Composer和Nginx/FPM本地可复用。成功或失败runtime都保留，不对其强行resume或重复seed。时间/早期job只由资格受限probe做最小合成控制，实际交易文件均位于新release外，原public/index和App源码字节保留。

本轮覆盖SQLite真实事务及原缓存生产Runtime；不外推MySQL锁竞争、跨worker并发、支付商户/SMTP或完整OCI/Engine/systemcron/root身份切换与实际部署schema。环境关闭第三方交易/通知feature，不请求支付网关。整体goal仍active，根PHP/framework正式切换仍待其余门槛证据。
