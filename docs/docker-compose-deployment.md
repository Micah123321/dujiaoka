# Docker Compose 部署教程

本教程适用于当前仓库的 Linux Docker 部署。命令在仓库根目录的 Bash 终端执行，将示例域名、服务器和备份目录替换为实际值。新店按第 1～5 节安装，已有店铺直接按第 10 节迁移。

部署由 [Dockerfile](../Dockerfile)、[Compose 配置](../docker-compose.yml)、[Compose 环境模板](../compose.env.example)、[启动脚本](../deploy/entrypoint.sh) 和 [应用运行模板](../deploy/runtime.env.example) 定义。默认镜像为 `ghcr.io/micah123321/dujiaoka:latest`，应用平台固定为 `linux/amd64`，运行 PHP 8.5 / Laravel 13。PHP/Nginx 基础镜像固定 digest；Node 22 多阶段构建自动检查、测试并构建 Modern 主题，无需在服务器另装 PHP、Composer 或 Node。

> 以下教程与 CI smoke 检查用于部署和基础运行验证，均不代表实际商户支付、真实回调、发货或邮件送达已验收。上线前仍需使用店铺实际配置验证交易全流程。

## 1. 准备服务器与配置

准备 Linux amd64 服务器、当前仓库源码、Docker Engine 和支持 `--wait` 的 Docker Compose v2 插件。安装方式见 [Docker Engine](https://docs.docker.com/engine/install/) 与 [Compose 插件](https://docs.docker.com/compose/install/linux/)。ARM 主机运行此应用镜像需要 amd64 仿真，应另行核验兼容性与性能。

```sh
git clone https://github.com/Micah123321/dujiaoka.git
cd dujiaoka
docker version
docker compose version
cp compose.env.example .env.compose
chmod 600 .env.compose
openssl rand -hex 32
openssl rand -hex 32
```

编辑新建的 `.env.compose`：把两次随机结果分别填入 `DB_PASSWORD`、`MYSQL_ROOT_PASSWORD`，使用两个不同的密码，并设置 `APP_URL`。保留模板中的用户名、库名，或同时修改为自己的值：

```dotenv
COMPOSE_PROJECT_NAME=dujiaoka
DUJIAOKA_IMAGE=ghcr.io/micah123321/dujiaoka:latest
HTTP_BIND=127.0.0.1
HTTP_PORT=8010
APP_URL=http://localhost:8010
ADMIN_HTTPS=false
DB_DATABASE=dujiaoka
DB_USERNAME=dujiaoka
DB_PASSWORD=REPLACE_WITH_FIRST_RANDOM_PASSWORD
MYSQL_ROOT_PASSWORD=REPLACE_WITH_SECOND_RANDOM_PASSWORD
```

所有操作栈的 Compose 命令都显式带 `--env-file .env.compose`；应用 `.env` 与它职责不同。保持项目名称和部署目录稳定，避免意外切换到另一套 named volume。检查配置时使用不输出密码的命令：

```sh
docker compose --env-file .env.compose config --quiet
```

数据库环境变量只在 MySQL 数据卷首次初始化时创建库与用户。已有卷上修改密码变量不会同步修改数据库账户；后续轮换应先在数据库内调整账户，再同步配置，保留备份和恢复窗口。

## 2. 拉取镜像并启动

推荐直接使用发布镜像：

```sh
docker compose --env-file .env.compose pull
docker compose --env-file .env.compose up -d --no-build --wait
docker compose --env-file .env.compose ps
```

`web` 内运行 Nginx、PHP-FPM、Supervisor 队列和系统 cron；另外两个服务为 `mysql:8.4`、`redis:7-alpine`。MySQL 与 Redis 就绪后启动 web，Redis 使用 AOF 持久化。三个服务的健康检查应显示 `healthy`；web 检查只探测首页 HTTP，因此健康通过仍需继续完成安装与迁移。

需要从当前源码构建时，使用以下命令代替上面的拉取与启动步骤：

```sh
docker compose --env-file .env.compose up -d --build --wait
```

### GHCR 拉取提示需要登录时

首次发布的 GHCR package 可能为 Private。仓库维护者可在 GitHub 的 Packages → 对应包 → Package settings 中手动将可见性设为 Public；公开仓库不等于其 package 已公开。本教程和工作流均不自动改变包权限。

保持 Private 时，使用有该包访问权的 GitHub 账号与具有 `read:packages` 的 PAT 登录后重试拉取，按组织要求完成 SSO 授权：

```bash
read -rsp 'GHCR PAT (read:packages): ' GHCR_PAT
printf '%s\n' ''
printf '%s' "$GHCR_PAT" | docker login ghcr.io -u YOUR_GITHUB_USER --password-stdin
unset GHCR_PAT
docker compose --env-file .env.compose pull
docker compose --env-file .env.compose up -d --no-build --wait
```

## 3. 浏览器安装与初始化

默认仅监听服务器的 `127.0.0.1:8010`。在服务器本机访问 `http://localhost:8010/install`；从个人电脑连接远程服务器时，可先建立 SSH 隧道，再在个人电脑浏览器打开该地址：

```sh
ssh -N -L 8010:127.0.0.1:8010 YOUR_SSH_USER@YOUR_SERVER
```

只对全新空库运行安装器。容器模式会预填连接地址、数据库名称、用户名和站点 URL；密码仍需手动输入。提交时连接参数须与 `.env.compose` 一致，参数不匹配会在导入前提示。安装表单填写：

| 字段 | 值 |
| --- | --- |
| 数据库地址 / 端口 | `mysql` / `3306` |
| 数据库名称 | 与 `.env.compose` 的 `DB_DATABASE` 一致，默认 `dujiaoka` |
| 数据库用户名 | 与 `DB_USERNAME` 一致，默认 `dujiaoka` |
| 数据库密码 | 与 `DB_PASSWORD` 一致，使用应用用户密码而非 root 密码 |
| Redis 地址 / 端口 | `redis` / `6379` |
| Redis 密码 | 留空；当前 Redis 在 Compose 内部网络中且未设置密码 |
| 站点地址（如表单要求） | 与 `APP_URL` 一致 |

这里的 `mysql`、`redis` 是容器服务名；填写 `127.0.0.1` 会指向 web 容器自身。数据库和 Redis 没有映射宿主机端口。

安装器导入基础安装 SQL、写入最终 `APP_KEY` 并创建安装锁，**安装后还需手动运行 Laravel 迁移**：

```sh
docker compose --env-file .env.compose exec --user application web php artisan migrate --force
docker compose --env-file .env.compose restart web
docker compose --env-file .env.compose ps
```

启动脚本不自动迁移。安装锁出现后队列开始工作；重启 web 让常驻 worker 使用安装器生成的最终 `APP_KEY`，并重新加载应用配置。迁移与重启完成后访问 `/admin`，默认账号/密码为 `admin` / `admin`，立即修改，再开放外部访问。

## 4. 域名、HTTPS 与公网访问

域名部署推荐宿主机 Nginx 反向代理至 `http://127.0.0.1:8010`，保持 `HTTP_BIND=127.0.0.1`。把域名解析到服务器，开放 80/443，准备宿主机 Nginx 与 Certbot Nginx 插件。以下以 Debian/Ubuntu 为例：

```sh
sudo apt-get update
sudo apt-get install -y nginx certbot python3-certbot-nginx
```

创建宿主机 `/etc/nginx/conf.d/dujiaoka.conf`，替换 `shop.example.com`：

```nginx
server {
    listen 80;
    server_name shop.example.com;
    client_max_body_size 20m;

    location / {
        proxy_pass http://127.0.0.1:8010;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

检查并加载配置，然后签发证书、启用 HTTPS 跳转；签发时域名和 80 端口须可访问。检查定期续签已配置：

```sh
sudo nginx -t
sudo systemctl reload nginx
sudo certbot --nginx -d shop.example.com --redirect
sudo certbot renew --dry-run
```

修改 `.env.compose` 中的两项，再重建 web 容器环境：

```dotenv
APP_URL=https://shop.example.com
ADMIN_HTTPS=true
```

```sh
docker compose --env-file .env.compose up -d --no-build --force-recreate --wait web
```

访问 `https://shop.example.com`、后台登录和静态资源，检查生成的付款 return/notify URL 使用正确域名与 HTTPS。反代的上传限制还受镜像内 Nginx/PHP 限制，应按业务需求核验。

如需公网直连端口，修改 `HTTP_BIND=0.0.0.0`、设置 `APP_URL=http://服务器地址:8010` 并重建 web；同时按需要放行宿主机防火墙端口。纯 HTTP 场景保留 `ADMIN_HTTPS=false`。正式店铺使用 HTTPS 传输后台密码与支付信息。

## 5. 持久化、配置覆盖与业务开关

| Named volume | 容器路径 | 内容 |
| --- | --- | --- |
| `app_data` | `/data` | 应用 `.env` 与 `install.lock`，包括必须保留的 `APP_KEY` |
| `app_storage` | `/app/storage` | 日志、文件会话、框架缓存及应用存储 |
| `app_uploads` | `/app/public/uploads` | 商品图片等上传文件 |
| `mysql_data` | `/var/lib/mysql` | 数据库 |
| `redis_data` | `/data`（Redis 容器） | Redis AOF、队列与缓存 |

启动脚本首次生成 `/data/.env`，后续保留现有文件；自动将 `/app/.env` 和 `/app/install.lock` 分别链接到 `/data/.env`、`/data/install.lock`，并设置应用用户权限。默认实际卷名带项目名前缀，如 `dujiaoka_app_data`。容器删除与重建保留 named volume；日常维护不要删除卷或执行 `down -v`。

业务开关在 web 的 `/data/.env` 修改，而不是宿主机的 `.env.compose`。可用容器与本地临时文件之间的复制方式编辑；临时文件含密钥与凭据，妥善保管，编辑后删除临时副本：

```sh
umask 077
docker compose --env-file .env.compose cp web:/data/.env ./runtime.env.edit
# 使用本地编辑器修改 runtime.env.edit，保留 APP_KEY。
docker compose --env-file .env.compose cp ./runtime.env.edit web:/data/.env
docker compose --env-file .env.compose exec --user root web chown application:application /data/.env
docker compose --env-file .env.compose exec --user root web chmod 600 /data/.env
docker compose --env-file .env.compose exec --user application web php artisan config:clear
docker compose --env-file .env.compose restart web
rm ./runtime.env.edit
```

Compose 的 `environment` 优先于应用文件：当前强制提供 `APP_ENV`、`APP_DEBUG`、`APP_URL`、数据库连接、Redis 主机/端口、`CACHE_DRIVER`、`QUEUE_CONNECTION`、`SESSION_DRIVER` 和 `ADMIN_HTTPS`。只在 `/data/.env` 改这些值不会覆盖容器环境；通过 `.env.compose` 支持的变量修改后，执行：

```sh
docker compose --env-file .env.compose exec --user application web php artisan config:clear
docker compose --env-file .env.compose up -d --no-build --force-recreate --wait
```

单纯 `restart` 不更新 Compose 环境变量。Compose 中直接写死的值需修改 [Compose 配置](../docker-compose.yml) 后重建；例如 `QUEUE_CONNECTION=redis` 不是此模板可直接切换的变量。不要用 `key:generate` 覆盖已有 `APP_KEY`，否则原 Cookie、加密邮件快照、支付签名恢复会受影响。

付款与补偿功能默认关闭，按对应文档完成迁移和渠道配置后再启用：

| 开关 | 部署说明 |
| --- | --- |
| `PAYMENT_QUOTE_FREEZE` | [冻结报价](payment-quotes.md) |
| `STRIPE_PAYMENT_INTENTS`、`STRIPE_RECONCILIATION` | [Stripe Payment Intents](stripe-payment-intents.md)、[Stripe 签名事件与对账](stripe-webhooks.md) |
| `PAYPAL_ORDERS_V2`、`PAYPAL_ORDERS_MODE`、`PAYPAL_RECONCILIATION`、`PAYPAL_WEBHOOK_IDS` | [PayPal Orders](paypal-orders.md)、[PayPal 签名事件与对账](paypal-webhooks.md) |
| `API_HOOK_OUTBOX`、`PAYMENT_MAIL_OUTBOX` | [付款通知持久补偿](notification-outbox.md) |
| `MANUAL_STATUS_MAIL_OUTBOX` | [人工订单状态邮件补偿](order-status-mail-outbox.md) |

## 6. 队列、cron 与平滑停止

[队列脚本](../deploy/queue-worker.sh) 在安装锁出现后启动 worker，[Supervisor 配置](../deploy/supervisor/queue.conf) 使用 `application` 用户、一个进程、`--sleep=3 --tries=2 --timeout=60`、90 秒停止等待；[队列配置](../config/queue.php) 的 Redis `retry_after` 为 90 秒。60 秒任务超时须小于 90 秒重试窗口，避免同一任务尚未结束便重新领取。

[系统 cron](../deploy/cron/dujiaoka) 在安装锁存在时每分钟以 `application` 执行 `schedule:run`，输出至 `/app/storage/logs/scheduler.log`；无需再给本栈重复添加宿主机定时任务。业务定时任务仍由功能开关控制。

Compose 使用 SIGTERM，并为 web 设置 120 秒停止宽限。正常停止会给 worker 留出在途任务排空时间；更新和备份应预留这一窗口，不要用短超时或强制 kill 代替：

```sh
docker compose --env-file .env.compose stop web
# 维护完成后启动。
docker compose --env-file .env.compose up -d --no-build --wait web
```

## 7. 更新与固定版本回滚

先按第 8 节备份，暂停新订单/付款，并记录更新前 web 的镜像 ID。可查看当前镜像与容器使用的实际 image ID：

```sh
docker compose --env-file .env.compose images web
docker inspect --format '{{.Image}}' "$(docker compose --env-file .env.compose ps -q web)"
```

更新发布镜像、执行新增迁移并重启 worker：

```sh
docker compose --env-file .env.compose stop web
docker compose --env-file .env.compose pull
docker compose --env-file .env.compose up -d --no-build --wait
docker compose --env-file .env.compose exec --user application web php artisan migrate --force
docker compose --env-file .env.compose restart web
docker compose --env-file .env.compose ps
```

这是现有单 web 实例的维护更新流程，不承诺零停机。迁移前保持外部访问暂停，更新后检查后台、商品、订单、队列及实际支付回调，再恢复业务。源码更新则检出所需版本后以 `docker compose --env-file .env.compose up -d --build --wait` 重新构建，再执行同样的迁移与重启步骤。

`latest`、`master` 属于可移动标签。需要固定发布或回滚应用时，在 GHCR 复制实际已发布的 `sha-...` 标签或镜像 digest，写入 `.env.compose`：

```dotenv
DUJIAOKA_IMAGE=ghcr.io/micah123321/dujiaoka:sha-REPLACE_WITH_PUBLISHED_SHA
# 或使用不可变 digest：
# DUJIAOKA_IMAGE=ghcr.io/micah123321/dujiaoka@sha256:REPLACE_WITH_PUBLISHED_DIGEST
```

```sh
docker compose --env-file .env.compose stop web
docker compose --env-file .env.compose pull web
docker compose --env-file .env.compose up -d --no-build --force-recreate --wait web
```

回滚只替换应用镜像，继续使用原卷、原 `APP_KEY` 与安装锁。先核实旧镜像兼容更新后的库结构；不要把 `migrate:rollback`、删表或删除数据卷当作常规回滚。涉及数据恢复时按第 8 节操作并核对维护窗口内的新增交易，避免丢失已付款记录。

## 8. 备份与恢复

备份至少包含 MySQL 逻辑导出和三个应用卷；`app_data` 保存密钥，应与数据一起保管。以下命令在 Linux Bash 执行，暂停入口并等待在途交易后备份。`stop web` 同时停止队列与 cron，备份失败时保持维护状态，排查后再恢复服务。

```bash
set -e
umask 077
BACKUP_DIR="backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$BACKUP_DIR"
docker compose --env-file .env.compose stop web
docker compose --env-file .env.compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --user=root --single-transaction --quick --routines --events --triggers --no-tablespaces --set-gtid-purged=OFF "$MYSQL_DATABASE"' > "$BACKUP_DIR/database.sql"
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'tar -czf - -C /data .' > "$BACKUP_DIR/app_data.tar.gz"
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'tar -czf - -C /app/storage .' > "$BACKUP_DIR/app_storage.tar.gz"
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'tar -czf - -C /app/public/uploads .' > "$BACKUP_DIR/app_uploads.tar.gz"
cp .env.compose "$BACKUP_DIR/.env.compose"
test -s "$BACKUP_DIR/database.sql"
gzip -t "$BACKUP_DIR/app_data.tar.gz" "$BACKUP_DIR/app_storage.tar.gz" "$BACKUP_DIR/app_uploads.tar.gz"
sha256sum "$BACKUP_DIR/database.sql" "$BACKUP_DIR/"*.tar.gz > "$BACKUP_DIR/SHA256SUMS"
docker compose --env-file .env.compose up -d --no-build --wait web
```

将备份转存到独立设备/存储并限制访问；SQL 与配置可能包含客户、卡密、支付配置和密钥。归档检查只证明文件完整，恢复演练才验证可用。Redis 卷持续保存队列与 AOF，但上述备份不包含它；迁移或灾难恢复若需保留待处理任务，另对停止后的 `redis_data` 做卷备份，不把清空 Redis 当作恢复步骤。

以下恢复步骤适用于新建恢复环境及空应用卷、目标库；确保原服务处于维护状态，使用匹配的配置、项目名称与固定镜像。向有新交易的生产库直接覆盖恢复会丢失备份后的数据，应先核对交易与恢复点。

```bash
set -e
BACKUP_DIR=backups/REPLACE_WITH_BACKUP_TIMESTAMP
docker compose --env-file .env.compose stop web
docker compose --env-file .env.compose up -d --no-build --wait mysql redis
docker compose --env-file .env.compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root "$MYSQL_DATABASE"' < "$BACKUP_DIR/database.sql"
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'tar -xzf - -C /data' < "$BACKUP_DIR/app_data.tar.gz"
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'tar -xzf - -C /app/storage' < "$BACKUP_DIR/app_storage.tar.gz"
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'tar -xzf - -C /app/public/uploads' < "$BACKUP_DIR/app_uploads.tar.gz"
docker compose --env-file .env.compose up -d --no-build --wait web
docker compose --env-file .env.compose exec --user application web php artisan migrate --force
docker compose --env-file .env.compose restart web
```

恢复应用卷后，启动脚本重新修正权限与 symlink。保留备份中的 `APP_KEY` 和 `install.lock`，恢复已有店铺不再打开安装器；检查原账号、订单、上传文件及待处理支付后再开放流量。

## 9. 排障与健康检查

先检查容器、健康状态和日志：

```sh
docker compose --env-file .env.compose ps -a
docker compose --env-file .env.compose logs --tail=200 web mysql redis
docker inspect --format '{{json .State.Health}}' "$(docker compose --env-file .env.compose ps -q web)"
docker compose --env-file .env.compose exec --user application web php artisan migrate:status
docker compose --env-file .env.compose exec web supervisorctl status
docker compose --env-file .env.compose exec --user application web tail -n 100 /app/storage/logs/scheduler.log
docker compose --env-file .env.compose exec web sh -c 'test -s /data/.env && test -f /data/install.lock && test -L /app/.env && test -L /app/install.lock'
```

最后一条只检查文件存在与链接状态，不输出密钥；新店安装前缺少安装锁是正常的。cron 日志在首轮执行后才生成。分享日志前检查是否包含业务信息；不要粘贴真实环境文件、完整 `docker inspect` 环境或 `docker compose --env-file .env.compose config` 输出。

| 现象 | 检查方向 |
| --- | --- |
| GHCR denied / unauthorized | 包可见性、账号包访问权、PAT 的 `read:packages` 与组织 SSO |
| 外部浏览器连接失败 | 默认仅 loopback；使用 SSH 隧道或 HTTPS 反代，核对防火墙、DNS、端口占用 |
| MySQL unhealthy / Access denied | 首次密码是否已替换；旧卷内账户密码是否与当前变量一致，查 MySQL 日志，保留卷 |
| 安装页 Redis 连接失败 | 地址应为 `redis`，端口 `6379`，密码留空，核对 Redis health |
| 安装成功后缺表 | 执行手动迁移；启动脚本和安装 SQL 不代替 Laravel migrations |
| 配置修改未生效 | Compose environment 覆盖、应用配置缓存、是否执行 force-recreate 或重启 worker |
| 页面 500 / 上传失败 | web 与 storage 日志、文件权限、完整迁移、磁盘空间、上传大小限制 |
| 队列无消费 / 补偿不执行 | 安装锁、Supervisor worker、Redis、cron 日志、功能开关及对应表是否齐全 |
| HTTPS 后资源或回调为 HTTP | `APP_URL`、`ADMIN_HTTPS`、反代 Host/协议头和配置缓存 |

## 10. 旧店迁移

旧店迁移复用原业务数据，不执行 `/install`，不重新导入基础安装 SQL，不生成新密钥，也不删除任何旧卷。先暂停旧店的新交易、worker 和调度，确认在途支付并备份原库、上传、storage、原应用 `.env` 及 `install.lock`。

1. 按第 1 节准备新栈，设置目标数据库用户名/密码和站点 URL；拉取镜像后仅启动 MySQL 与 Redis。
2. 手动导入原库，将旧上传目录内容放入 `app_uploads`，将原应用环境文件和安装锁放入 `app_data`。保留原 `APP_KEY`；如果有文件会话或 storage 附件，一并迁移 `app_storage`。
3. 启动 web、执行前向迁移、重启 web，核对后台原账号、原订单、图片、业务开关与支付回调后切换流量。旧站与新站不要同时处理同一订单。

假设原库导出为 `old-shop.sql`、原配置/锁位于 `migration/`，上传目录打包为 `old-uploads.tar.gz`（归档根目录直接为上传内容），可执行：

```sh
docker compose --env-file .env.compose pull
docker compose --env-file .env.compose up -d --no-build --wait mysql redis
docker compose --env-file .env.compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --user=root "$MYSQL_DATABASE"' < old-shop.sql
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'cat > /data/.env' < migration/.env
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'cat > /data/install.lock' < migration/install.lock
docker compose --env-file .env.compose run --rm --no-deps -T --user root --entrypoint sh web -c 'tar -xzf - -C /app/public/uploads' < old-uploads.tar.gz
docker compose --env-file .env.compose up -d --no-build --wait web
docker compose --env-file .env.compose exec --user application web php artisan migrate --force
docker compose --env-file .env.compose restart web
```

原库导出应针对单一业务库；若包含旧库名的 `CREATE DATABASE` / `USE`，先调整目标或重新导出，避免导入其他库。原数据库/Redis地址由 Compose 环境覆盖，SMTP、支付开关等沿用原应用文件；当前 Redis 无密码，迁移前将原配置中的 `REDIS_PASSWORD` 调整为 `null`。迁移旧队列时单独处理 Redis 状态或先排空旧任务；旧 Redis 服务不得与新 worker 并行消费相同业务。保留原安装锁后首次启动自动建立链接和权限。

## 11. GitHub Actions 构建与发布

GitHub Actions 中选择 [Docker image 工作流](../.github/workflows/docker-image.yml)：`master` / `main` push、`v*` tag 和 `workflow_dispatch` 触发自动测试、镜像构建与 GHCR 发布；Pull Request 运行验证但不发布。

默认分支 push 发布 `latest`；分支 push 发布对应的 `master` / `main` 标签，版本 tag 发布原名 `v*` 标签，同时发布 `sha-` 加短提交 SHA 的标签。部署与回滚以 GHCR 实际生成的标签/digest 为准；固定 PHP/Nginx 基础 digest 不等于 `latest` 应用镜像固定。

工作流还上传 `dujiaoka-compose` artifact，包含部署配置、环境模板、教程及实际镜像标签/digest；可在对应 Actions 运行页面下载，保留其中版本信息供回滚核对。从 artifact 部署使用发布镜像路径；从源码构建则需要完整仓库。

工作流使用 GitHub 默认 `GITHUB_TOKEN` 和 `packages: write` 权限发布 GHCR，无需额外配置 Docker Hub secret。首次发布后检查包可见性；需要公开时由维护者手动设置 Public，Private 拉取按第 2 节登录。

Actions 的测试、构建和 smoke 通过，代表该次流程规定的检查通过；正式上线仍需核验持久化、真实调度、实际商户支付及回调/发货全流程。
