<p align="center"><img src="https://i.loli.net/2020/04/07/nAzjDJlX7oc5qEw.png" width="400"></p>

<p align="center">
<a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/license-MIT-blue" alt="license MIT"></a>
<a href="https://github.com/assimon/dujiaoka/releases/tag/2.0.4"><img src="https://img.shields.io/badge/version-2.0.4-red" alt="version 2.0.4"></a>
<a href="https://www.php.net/supported-versions.php"><img src="https://img.shields.io/badge/PHP-8.5-lightgrey" alt="php85"></a>
<a href="https://shang.qq.com/wpa/qunwpa?idkey=37b6b06f7c941dae20dcd5784088905d6461064d7f33478692f0c4215546cee0"><img src="https://img.shields.io/badge/QQ%E7%BE%A4-568679748-green" alt="QQ群：568679748"></a>
</p>

## 独角数卡

开源式站长自动化售货解决方案、高效、稳定、快速！

- 框架来自：[laravel/framework](https://github.com/laravel/laravel).
- 后台管理系统：[laravel-admin](https://laravel-admin.org/).
- 前端ui [bootstrap](https://getbootstrap.com/).

核心贡献者：
- [iLay1678](https://github.com/iLay1678)

模板贡献者：
- [Julyssn](https://github.com/Julyssn) 模板`luna`作者
- [bimoe](https://github.com/bimoe) 模板`hyper`作者

鸣谢以上开源项目及贡献者，排名不分先后.

当前[正式根依赖](docs/root-dependency-promotion.md)已切到PHP8.5.11 / Laravel13.35.0 / Dcat X2.0.4 / PHPUnit13.4.1，保留原root autoload及Composer hooks；Windows/Linux完整2331项/18310断言通过。回归与现代化进展见[测试入口](tests/README.md)，以下条目保留各轮历史证据和运行范围。

现有Docker Compose已补[120秒停止宽限](docs/container-stop-compatibility.md)，为在途队列任务留出排空时间；原生PID1/application身份与候选[真实分钟调度](docs/scheduler-compatibility.md)已通过，完整镜像和系统cron运行继续单独验收。候选同版PHP8.5.11已通过[真实Nginx/FPM请求链](docs/http-runtime-compatibility.md)，覆盖Vue资源、CSRF、文件会话、代理和维护恢复；正式根已完成[本地依赖切换](docs/root-dependency-promotion.md)，运营发布另行验收。

新旧框架已完成[文件cache/session与后台登录双向延续](docs/cross-version-state-compatibility.md)，并修复上传器和默认批删的PHP8.5动态属性弃用；完整旧根2301项、Windows/Linux候选2323项回归通过。

候选已通过[独立无开发依赖发布](docs/production-release-compatibility.md)：固定锁100运行包、root-local autoload、原生产缓存、850后台资源正式发布及真实Nginx/FPM32次请求通过，源码/vendor均实际复制。这是原候选发布证据；[当前正式根](docs/root-dependency-promotion.md)与完整容器/系统cron保留各自验收范围。

[优惠券商品关联](docs/admin-coupon-compatibility.md)已补真实多选新建、替换、清空与两关系写入故障事务回滚；双框架浏览器及完整Windows旧根2305项/候选2327项通过，Linux新增四例聚焦通过。

[独立发布真实交易](docs/production-transaction-compatibility.md)已通过无开发依赖、生产缓存条件下的原Nginx/FPM下单、实际数据库延迟任务、原worker提前处理及双过期命令幂等补偿，原加密Cookie与失败UUID记录另有负控制证据。

[固定PHP8.5镜像输入](docs/modern-image-preparation.md)已准备独立候选构建目录，官方native摘要、原接受源码/850资产、固定候选锁和实际Compose模型经过3823项核对；完整镜像构建及Engine运行仍待验收。

[原队列跨框架迁移](docs/cross-version-queue-compatibility.md)已验证旧整数失败ID、双向原载荷消费、三次失败全回滚及原重试后仅退券一次；PHP8.5启动期验证码弃用已用应用适配修复，保留原Provider身份/发布/校验，Windows完整旧根2309项与候选2331项通过。

[完整 Linux 与最终发布验收](docs/complete-release-observation.md)现已实跑：Linux2331/18310与Windows候选全身份一致；新的独立发布545检查/57请求/35FPM，配置和路由缓存的二次App观察均纳入，全部17原PHP角色独立复核37157通过。

[原固定镜像PHP运行验收](docs/native-image-runtime.md)已完成20原层/diffID链、原PHP8.5.11/Composer平台21项、coldApp144与独立37993文件和运行证据检查；完整Engine、应用用户降权与服务/cron仍待验收。

[原镜像Nginx/FPM验收](docs/original-image-http.md)已完成33真实HTTP/11FPM、8095合同与44281独立检查；修正CLI运行完整PASS并通过39274强复核。当前仍是单UID隔离测试配置，Engine、原应用用户降权与系统cron待验收。

[原镜像队列与调度验收](docs/original-image-queue.md)已完成原Supervisor、15事件/4worker/17命令、三次原子回滚与UUID恢复、两次原schedule子命令；独立53317项检查通过。

[原application身份验收](docs/original-image-application-user.md)完成UID/GID1000、五能力零/NoNewPrivs1下原Supervisor队列与调度；63404内部及53614独立检查通过。第54轮[正式根切换](docs/root-dependency-promotion.md)及新的no-dev发布545/57HTTP/35FPM、独立37173复核已通过；完整Engine、系统cron及商户运营发布另行验收。

## 系统优势

采用业界流行的`laravel`框架，安全及稳定性提升。    
支持`自定义前端模板`功能   
支持`国际化多语言包`（需自行翻译）  
代码全部开源，所有扩展包采用composer加载，代码所有内容可溯源！     
长期技术更新支持！

## 👮‍♂️反诈提醒
近期有高仿作者telegram实施诈骗的行为，        
请各位用户擦亮双眼，作者从不私聊任何人提供任何收费形式的技术支持服务！    
项目是完全开源免费的，完全遵守`MIT开源协议`    
没有所谓的`技术支持`，`终身技术支持`，`二次开发`等业务，也不参与任何用户的`业务运营`。    
凡是作者私聊你的全是高仿号诈骗，请仔细核对作者唯一Telegram用户名：`linux0101`     

## 写在前面
本程序有一定的上手难度（对于小白而言），需要您对linux服务器有基本的认识和操作度   
且本程序不支持虚拟主机，大概率也不支持windows服务器！  
如果您连宝塔、phpstudy、AppNode等一键可视化服务器面板也未曾使用或听说过，那么我大概率劝您放弃本程序！  
如果您觉得部署有难度，建议仔细阅读（仔细！）宝塔视频安装篇教程，里面有保姆级的安装流程和视频教程！   
认真观看部署教程我可以保证您98%可能性能部署成功！  
勤动手，多思考，善研究！

## 使用交流      
Telegram: [https://t.me/dujiaoka](https://t.me/dujiaoka)    
关注Telegram官方频道：[https://t.me/dujiaoshuka](https://t.me/dujiaoshuka) (系统更新通知，bug更新，重大事件推送)

## 🔥推荐服务器 
- （🇭🇰香港三网(电信/移动/联通)直连优化VPS，CN2优化网络大带宽低至35RMB/每月）[👉🏻点我直达](https://www.vkvm.info/cart?action=configureproduct&pid=146&aff=ECRPONNJ)
- （🇺🇸美国免备案vps，配置2核2G仅需`20.98$`≈`145RMB`一年/支持支付宝付款）[👉🏻点我直达](https://my.racknerd.com/aff.php?aff=2745&pid=681)

## 界面尝鲜
【官方unicorn模板】
![首页.png](https://i.loli.net/2021/09/14/NZIl6s9RXbHwkmA.png)

【luna模板】 
![首页.png](https://i.loli.net/2020/10/24/ElKwJFsQy4a9fZi.png)

【hyper模板】  
![首页.png](https://i.loli.net/2021/01/06/nHCSV5PdJIzT6Gy.png)

## 安装篇

- [Docker Compose 完整部署教程（当前镜像）](docs/docker-compose-deployment.md)
- [Linux环境安装](https://github.com/assimon/dujiaoka/wiki/linux_install)
- [Docker安装](https://github.com/assimon/dujiaoka/wiki/docker_install)
- [2.x版本宝塔安装教程](https://github.com/assimon/dujiaoka/wiki/2.x_bt_install)
- [1.x版本宝塔环境安装](https://github.com/assimon/dujiaoka/wiki/1.x_bt_install)
- [常见问题锦集-你遇到的问题大部分能在这里找到解决！！](https://github.com/assimon/dujiaoka/wiki/problems)
- [系统升级](https://github.com/assimon/dujiaoka/wiki/update)
- [各支付对应后台配置](https://github.com/assimon/dujiaoka/wiki/problems#各支付对应配置)
- [视频教程及工具集合](https://pan.dujiaoka.com)

Docker Compose 快捷启动：先复制模板，编辑 `.env.compose`，替换两个不同的随机数据库密码并设置 `APP_URL`（默认只监听 `127.0.0.1:8010`）。

```sh
cp compose.env.example .env.compose
# 编辑 .env.compose：DB_PASSWORD、MYSQL_ROOT_PASSWORD、APP_URL。
docker compose --env-file .env.compose pull
docker compose --env-file .env.compose up -d --no-build --wait
```

新店打开 `/install`，数据库填写 `mysql:3306` 及模板一致的库名、用户名和密码，Redis 填写 `redis:6379`、密码留空。安装成功后执行：

```sh
docker compose --env-file .env.compose exec --user application web php artisan migrate --force
docker compose --env-file .env.compose restart web
```

访问 `/admin` 后立即修改默认 `admin/admin`。从源码构建使用 `docker compose --env-file .env.compose up -d --build --wait`；域名 HTTPS、持久化、备份、更新/回滚及旧店迁移见[完整教程](docs/docker-compose-deployment.md)。旧店复用原库和 `APP_KEY`，不要重跑安装器或删除数据卷。

## 支付接口已集成
- [x] 支付宝当面付
- [x] 支付宝PC支付
- [x] 支付宝手机支付
- [x] [payjs微信扫码](http://payjs.cn).
- [x] [Paysapi(支付宝/微信)](https://www.paysapi.com/).
- [x] 码支付(QQ/支付宝/微信)
- [x] 微信企业扫码支付
- [x] [Paypal支付(默认美元)](https://www.paypal.com)
- [x] V免签支付
- [x] 全网易支付支持(通用彩虹版)
- [x] [stripe](https://stripe.com/)

## 基本环境要求

- PHP + PHPCLI >= 8.4，当前正式根完整验收为8.5.11；Web与CLI使用同版本及完整扩展。
- Nginx version >= 1.16
- MySQL：锁与迁移已在隔离8.4.11验证；现有店库结构与升级须按实际环境验收。
- Redis (高性能缓存服务)
- Supervisor (一个python编写的进程管理服务)
- Composer (PHP包管理器)
- 部署使用Linux；Windows/Linux已覆盖完整PHP回归，Nginx/FPM、PCNTL、Supervisor与调度验收使用Linux。

## PHP环境要求

星号(*)为必须执行的要求，其他为建议内容

- **\*安装`fileinfo`扩展**
- **\*安装`redis`扩展**
- **\*终端需支持`php-cli`，测试`php -v`(版本必须一致)**
- **\*需要开启的函数：`putenv`，`proc_open`，`pcntl_signal`，`pcntl_alarm`**
- 安装`opcache`扩展

## 默认后台

- 后台路径 `/admin`
- 默认管理员账号 `admin`
- 默认管理员密码 `admin`

## 免责声明

独角数卡程序是免费开源的产品，仅用于学习交流使用！       
不可用于任何违反`中华人民共和国(含台湾省)`或`使用者所在地区`法律法规的用途。      
因为作者即本人仅完成代码的开发和开源活动`(开源即任何人都可以下载使用)`，从未参与用户的任何运营和盈利活动。    
且不知晓用户后续将`程序源代码`用于何种用途，故用户使用过程中所带来的任何法律责任即由用户自己承担。      


## Thanks

Thanks JetBrains for the free open source license

<a href="https://www.jetbrains.com/?from=gev" target="_blank">
	<img src="https://i.loli.net/2021/02/08/2aejB8rwNmQR7FG.png" width = "260" align=center />
</a>


## License

独角数卡 DJK Inc [MIT license](https://opensource.org/licenses/MIT).
