# 队列运行约定

旧版Laravel6读取任务`retryAfter`，现代框架按`backoff()`生成队列payload；ApiHook已用兼容入口读取同一30秒来源。真实数据库worker先复现Laravel13失败后立即释放，修复后验证到期前没有第二次请求、仅到期后重试，并确认任务自身两次上限优先于worker参数。用例使用固定时钟，仅调整隔离任务available_at模拟到期，没有实际等待30秒。ApiHook保持最多两次尝试，失败后延迟30秒；HTTP连接超时5秒、请求总超时15秒，任务超时30秒。只有2xx响应算成功；重定向不跟随，需将商品配置指向最终回调地址。原JSON字段保持不变。接收方应按order_sn幂等处理：超时可能发生在接收方已处理但响应未返回之后。

上述ApiHook两次尝试是默认旧模式。商品回调已增加可选的[持久补偿](notification-outbox.md)：建表并启用API_HOOK_OUTBOX后，在付款事务内保存投递意图，发布失败与任务失联由定时补扫恢复，最多五次执行尝试。默认关闭；付款邮件另由PAYMENT_MAIL_OUTBOX启用加密快照补偿，人工订单状态邮件和其他推送仍未接入。同步队列不执行原异步重试；新outbox会记录失败并等待补扫，正式运行仍应使用持久队列驱动。

## 邮件配置刷新

MailSend根据实际邮件API兼容两条路径：Laravel6重注册服务并清除mailer门面实例；Symfony在mail.manager上清空named mailer缓存并清除对应门面键，保留自定义传输创建器。连续任务验证SMTP主机、用户名和发件地址均刷新。SMTP socket等待为15秒；旧框架直接设置Swift_SmtpTransport，新框架设置stream timeout。该超时不是会话总时长，30秒任务超时仍需worker支持。ssl/tls/空配置在自定义端口和465都保持显式语义，STARTTLS握手还拦截EHLO失败后HELO明文回退，详见[双框架邮件验证](mail-runtime.md)。

MailWorkerTest先复现连续任务使用旧发件地址，再验证刷新后的发件人、收件人、SMTP主机和超时。array驱动保留测试邮件；SMTP测试在MessageSending阶段取消，确认传输未启动，不向外发送消息。

付款邮件开启PAYMENT_MAIL_OUTBOX后采用同一outbox的payment-mail通道：在付款事务内加密保存收件人及替换数据，执行时渲染模板并采用最新SMTP配置；缺失模板或SMTP失败进入持久重试。严格确认在Swift路径统计接受收件人数并检查失败名单，finally恢复邮件器；Symfony路径要求非空SentMessage及非空收件信封。取消、空结果/零接受或异常均保留密文并等待重试。邮件传输接受后清除密文快照；不证明最终收件箱到达，也可能因成功落库前失联重复发送。详见[付款通知补偿](notification-outbox.md)。

## Bark 与企业微信通知

BarkPush、WorkWeiXinPush使用容器中的Guzzle客户端，连接超时5秒、请求总超时15秒，开启TLS证书校验且不跟随重定向。请求模板和原表单/JSON字段保持；配置应指向最终地址，自建Bark需使用有效证书链。商品服务改为任务执行时解析，保留旧序列化任务属性。

只有2xx且包含成功业务回包才算接口接收：Bark的code为200，企业微信的errcode为0。3xx、4xx/5xx、业务错误、空回包和无效JSON都会抛异常交给worker处理。错误消息只包含通道、HTTP状态或失败类别，不携带URL、token或响应正文，也不附加原传输异常。任务尝试上限仍为Bark两次、企业微信一次；企业微信失败进入failed_jobs后需按现有运维方式处理，不能把worker的--tries=2理解为覆盖任务自身配置。入队前持久补偿仍待实现，接口成功也不证明用户设备已展示通知。

成功回包依据：[企业微信消息推送说明](https://developer.work.weixin.qq.com/document/path/91770)、[Bark官方路由](https://github.com/Finb/bark-server/blob/master/route_push.go)及[成功响应定义](https://github.com/Finb/bark-server/blob/master/router.go)。

## 托管参数

在现有进程管理器中使用项目的PHP解释器和目录运行 `php artisan queue:work --sleep=3 --tries=2 --timeout=60`。进程应自动重启、按应用用户运行，并保留错误日志。停止等待时间应大于worker超时，例如90秒。当前database/redis连接retry_after为90秒，必须大于worker超时，避免任务执行中再次被领取。多节点应共享队列和缓存配置。

现有系统调度器每分钟运行 `php artisan schedule:run`，其中orders:expire负责延迟任务失败时的过期补偿。部署后用原管理方式重启worker或 `php artisan queue:restart`；确认failed_jobs表存在并监控 `php artisan queue:failed`。重放前确认接收方幂等。以上是部署约定，本次未修改主机进程或调度器。

Dockerfile现使用基础镜像Supervisor入口，deploy/supervisor/queue.conf托管一个application用户worker，自动重启、停止等待90秒、日志输出stdout。deploy/cron/dujiaoka每分钟调度并写storage/logs/scheduler.log；按基础镜像契约启用cron。多副本部署需限制调度实例，避免重复调度。调度日志需要部署侧轮转。

构建不再忽略平台要求，禁用安装脚本/插件，排除本地vendor、测试运行时、环境文件和运行缓存；依赖按锁文件安装。应用目录归application，移除全目录777。绑定挂载的.env、install.lock和上传目录仍需部署侧设置application可访问的权限，镜像内chown不会改变挂载权限。原Compose绑定配置保持。

初始版本只有托管配置静态检查。第39轮已有真实用户态Supervisor/PCNTL证据，第40轮以当前镜像文件确认cron复制与服务include，并新增真正PID1/applicationUID1000的SIGTERM排空。正式Compose现显式SIGTERM/120s，满足外层120>Supervisor90>原job60；默认Docker Linux10秒会先于队列排空强杀。官方Compose模型双树通过，真实namespace在10.534秒仍持有任务，释放后正常结束，详见[模型、镜像和PID1证据](container-stop-compatibility.md)。容器引擎尚未安装或启动，完整镜像构建运行、实际Engine计时和系统cron分钟tick继续单独验收。第41轮新增[原生分钟调度与系统cron前提](scheduler-compatibility.md)证据；Laravel分钟循环和系统cron分别记录，正式crontab保留。直接Docker创建/停止/重启也应保留120秒超时；多服务整体停止仍需实测。

## 真实进程验收

[第35轮独立进程与调度报告](queue-lifecycle.md)已在PHP7.4/Laravel6和PHP8.5/Laravel13各完成五场景、24子PHP进程，真实数据库queue消费、双tick补扫、filecache重启排空及主动失联后的租约恢复通过。最终queue空、九outbox完成；主动失联任务达到一次队列上限，按预期留一failed_jobs记录，补偿任务仍完成。四次实际Event的mutex前持有后释放逐项验证，解释器/INI/DB/env/storage/source均匹配。

## 验证边界

ApiHookTest使用Guzzle MockHandler验证JSON字段、超时参数、重定向/4xx/5xx失败、连接错误向worker传播。PushNotificationTest另覆盖Bark/企业微信请求字段、TLS与超时选项、3xx/4xx/5xx、HTTP200业务错误/无效回包、传输异常脱敏及执行时解析商品服务。没有访问实际回调或推送服务，也没有证明真实网络超时耗时、证书握手或生产队列重试已经运行。
