# Xboard 代码更新、线上部署与多 Theme 域名指南

更新日期：2026-10-21（Asia/Shanghai）

本文对应逸云当前的部署方式：本地维护上游代码和定制修改，使用 GitHub Actions 构建自有镜像，云服务器通过 Docker Compose 部署明确的镜像 digest。

> 命令分为“Mac 本地”和“云服务器”两类，请在对应环境执行。出现错误时先停止当前阶段，不要继续执行后续修改。示例中的第二个 Theme 域名、备份时间和新 digest 均需要按实际情况替换。

## 1. 当前部署基线

| 项目 | 当前配置 |
| --- | --- |
| Mac 本地代码目录 | `/Users/mybook/Documents/Project/Xboard_Prod` |
| 自有仓库 origin | `https://github.com/xiaohuailaopo/Xboard-Yiyun.git` |
| 跟进的上游 upstream | `https://github.com/cedar2025/Xboard.git` |
| 自有维护分支 | `master` |
| 镜像仓库 | `ghcr.io/xiaohuailaopo/xboard-yiyun` |
| GitHub Actions 工作流 | `Build Yiyun Xboard`，手动触发 |
| 目标平台 | `linux/amd64` |
| 云服务器部署目录 | `/docker/Xboard` |
| Compose 服务名 / 容器名 | `xboard` / `xboard-xboard-1` |
| 共享 Docker 网络 | `shared_services` |
| Xboard 数据库 | MySQL 容器 `infra-mysql` 中的 `xboard` 库 |
| Nginx 容器 | `infra-nginx` |
| Theme 地址 | `https://yiyunplus.com` |
| Xboard 上游面板地址 | `https://admin.yiyunplus.com` |
| Elegant Proxy 地址 | `https://centre.yiyunplus.com` |
| Elegant Proxy 部署目录 / 服务名 | `/docker/elegant` / `proxy` |
| Xboard 资源档位 | `RESOURCE_PROFILE=balanced` |

本次已切换并确认容器使用的镜像：

```text
ghcr.io/xiaohuailaopo/xboard-yiyun@sha256:b2eedd0cad55c0f9e496571e0063bb6cb8935425c230ca406167b3621b1a393c
```

它是历史基线，不是以后每次升级都要使用的地址。后续应复制新构建 Summary 中的完整 digest。

已确认新支付代码在容器内、后台 `app_url` 为 `https://yiyunplus.com`；支付完成跳转和异步到账仍应通过实际新订单验收，不能仅凭代码存在或容器 Up 判断。

## 2. 必须保留的自有修改

| 文件 | 自有修改及升级时的检查点 |
| --- | --- |
| `app/Services/PaymentService.php` | 使用经白名单校验的 `X-Elegant-Theme-Origin`，否则回退后台 `app_url`；返回 `/dashboard/order/?id=订单号`；保持通知地址原逻辑 |
| `config/payment.php` | 从 `PAYMENT_THEME_ORIGINS` 读取额外 Theme 来源，默认 Theme 自动来自后台 `app_url` |
| `.env.example` | 说明 `PAYMENT_THEME_ORIGINS`，不填真实秘密 |
| `Dockerfile` | 接收 `SOURCE_COMMIT` 并检出、核验提交；设置版本信息；保留支付文件 PHP 语法检查 |
| `.github/workflows/docker-publish.yml` | 仅手动发布，构建自有仓库的精确提交；发布 amd64 镜像和代码 SHA 标签，在 Summary 输出 digest |

构建源码固定到提交，不代表基础镜像及外部依赖也永久不变。同一代码提交重新构建时，digest 仍可能变化；生产部署以实际 digest 为准。

不要把线上 `.env`、数据库备份、支付凭据、用户 UUID、完整订阅链接提交到公开仓库。真实配置和数据库继续留在服务器持久化目录中。

## 3. 日常更新：本地同步并合并上游

### 3.1 检查本地状态（Mac）

```bash
cd /Users/mybook/Documents/Project/Xboard_Prod

git status --short --branch
git remote -v
git branch --show-current
```

先提交或妥善保存已有修改，确保工作区干净。接下来使用 `master`；如果目前在其他分支，确认没有未提交工作后再切换。

```bash
git switch master
git fetch origin
git merge --ff-only origin/master

git fetch upstream
git log --oneline HEAD..upstream/master
```

若 `--ff-only` 报错，说明本地和自己的远程分支有分叉，先在 IDEA 中比较并处理，不要强制推送。

`git fetch` 只获取远程记录；真正合并之前先查看上游更新说明、数据库迁移和支付相关变更。

### 3.2 留下本地恢复点并合并（Mac）

```bash
git branch "codex/before-upstream-$(date +%Y%m%d-%H%M%S)"
git merge upstream/master
```

IDEA 也可以执行 Fetch、比较分支，再将 `upstream/master` 合并到本地 `master`。

有冲突时：

1. 优先检查第 2 节中的自有修改，以及上游支付插件和数据库迁移。
2. 在 IDEA 冲突编辑器中合并，保留新上游修复并重新应用自己的定制。
3. 不要整文件盲选“采用我的”或“采用对方的”，以免覆盖安全修复或丢失定制。
4. 解决后暂存具体文件，完成合并提交。

命令行检查及完成方式：

```bash
git diff --name-only --diff-filter=U
# 编辑并解决列出的文件后，用 git add 暂存实际解决的文件。
git merge --continue
```

如需放弃尚未完成的合并：

```bash
git merge --abort
```

### 3.3 检查支付修改和构建配置（Mac）

```bash
php -l app/Services/PaymentService.php
php -l config/payment.php
git diff --check
git status --short --branch
```

同时人工核对：

- `return_url` 使用 `$themeBase . '/dashboard/order/?id=' . rawurlencode((string) $order['trade_no'])`。
- 来源来自 `X-Elegant-Theme-Origin`，采用精确白名单比较，不直接信任任意请求头、Referer 或请求 Host。
- 缺失或未登记的来源回退到 `admin_setting('app_url')`。
- 未为了返回 Theme 修改 `notify_url` 或服务器 `.env` 的 `APP_URL`。
- 支付插件没有重新拼接旧的 `/#/order/` 等返回地址。当前 Epay 插件使用服务层传入的 `return_url`，升级后仍需检查。
- 工作流仍只包含 `workflow_dispatch`，不会因普通 push 或同步上游就自动发布。
- 工作流仍传递 `SOURCE_COMMIT=${{ github.sha }}`；Dockerfile 仍检出并核验该提交。

本机没有 Docker 时，PHP 检查只能说明本机语法有效；完整镜像构建及镜像内 PHP 检查由 Actions 执行。

### 3.4 提交并推送自己的仓库（Mac / IDEA）

纯合并且无额外工作时，合并提交已由 Git 生成。另有定制修改时，在 IDEA 中检查差异并提交，或明确暂存需要的文件后提交。

```bash
git push -u origin master
```

IDEA：`Git → Push`（Mac 通常为 `⌘ ⇧ K`），确认目标为自己的 `origin/master`。GitHub 账号通过 IDEA 的浏览器授权登录；HTTPS Git 操作不能使用 GitHub 账户密码。涉及工作流文件时，认证还需要允许修改工作流。

## 4. 手动构建并取得新镜像 digest

1. 打开 `https://github.com/xiaohuailaopo/Xboard-Yiyun/actions`。
2. 选择 **Build Yiyun Xboard**。
3. 点击 **Run workflow**，选择 `master`。
4. 等待构建成功；失败时先查看失败步骤日志，不部署不完整结果。
5. 在运行的 Summary 中复制 **Use this digest in Compose** 下的完整地址。

输出结构：

```text
代码标签：ghcr.io/xiaohuailaopo/xboard-yiyun:sha-完整代码提交号
部署地址：ghcr.io/xiaohuailaopo/xboard-yiyun@sha256:实际镜像摘要
```

构建和推送镜像不会自动更新云服务器。记录此次提交号、镜像 digest 和数据库迁移说明。镜像包若为私有，服务器需有 `read:packages` 等适当拉取权限；不要为排障公开令牌。

## 5. 线上部署：备份、拉取、切换

### 5.1 创建本次备份并固定旧镜像（云服务器）

下面各小节尽量在同一个终端执行。时间戳使用 UTC。重新连接后的变量恢复方式见本节末尾。

```bash
cd /docker/Xboard

release_stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_dir="/docker/backups/xboard-$release_stamp"
rollback_image="xboard-yiyun:rollback-$release_stamp"

mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
cp -a compose.yaml .env "$backup_dir/"

docker inspect xboard-xboard-1 --format '{{.Config.Image}}' \
  > "$backup_dir/previous-image-reference.txt"
docker inspect xboard-xboard-1 --format '{{.Image}}' \
  > "$backup_dir/previous-image-id.txt"

docker image tag "$(cat "$backup_dir/previous-image-id.txt")" "$rollback_image"
printf '%s\n' "$rollback_image" > "$backup_dir/rollback-image.txt"

printf '备份目录：%s\n回滚镜像：%s\n' "$backup_dir" "$rollback_image"
```

将终端显示的路径记下。文字形式的镜像 ID 不是镜像备份；独立回滚标签需要对应镜像仍存在于本机。验收之前不要执行镜像 prune。

根据本次更新范围，另行备份有改动的 `plugins/`、`storage/theme/` 或其他业务文件。它们不会因为替换镜像而自动包含在镜像备份中。

如果重新连接了终端，使用本次已经创建的实际备份目录恢复变量，不要重新生成备份时间戳：

```bash
backup_dir='/docker/backups/xboard-替换为本次已经创建的备份时间'
release_stamp="${backup_dir##*/xboard-}"
rollback_image="$(cat "$backup_dir/rollback-image.txt")"
test -d "$backup_dir" && test -s "$backup_dir/compose.yaml"
```

最后一条命令失败或 `cat` 报错时，先修正备份路径，不继续执行数据库备份。

### 5.2 备份 MySQL 的 xboard 库（云服务器）

输入的是现有 MySQL root 密码。SQL 写到容器文件，密码提示不会混入备份。

```bash
tmp_sql="/tmp/xboard-backup-${release_stamp:?请先创建或恢复本次备份变量}.sql"

test -d "${backup_dir:?请先创建或恢复本次备份变量}" \
  && docker exec -it infra-mysql sh -c \
  'umask 077; mysqldump -uroot -p \
    --single-transaction --no-tablespaces \
    --routines --events --triggers --set-gtid-purged=OFF \
    xboard > "$1"' sh "$tmp_sql"
```

命令无错误退出后，继续：

```bash
docker cp "infra-mysql:$tmp_sql" "$backup_dir/xboard.sql"
chmod 600 "$backup_dir/xboard.sql"
ls -lh "$backup_dir/xboard.sql"
tail -n 3 "$backup_dir/xboard.sql"
```

确认非空且正常完成，复制成功后清理容器临时文件：

```bash
docker exec infra-mysql rm "$tmp_sql"
```

文件非空及 `Dump completed` 是基本检查，不等于已验证可恢复。重要升级应事先在隔离数据库中验证恢复。`--single-transaction` 适用于事务表，备份期间避免执行 DDL；会改变表结构的升级应根据更新内容安排维护窗口。

若备份后继续产生新订单，备份恢复会丢失备份时点之后的数据。备份应尽量接近切换时间；重大迁移需安排写入暂停及恢复计划。

### 5.3 先拉取目标镜像（云服务器）

把下面占位地址替换为本次 Actions Summary 的真实地址：

```bash
new_image='ghcr.io/xiaohuailaopo/xboard-yiyun@sha256:替换为本次真实摘要'

docker pull "$new_image"
docker image inspect "$new_image" --format '{{.Os}}/{{.Architecture}}'
```

预期平台为 `linux/amd64`。`denied` 或 `unauthorized` 先处理镜像包可见性或登录权限，不要继续重建。拉取镜像不会停止现有容器。

### 5.4 修改 Compose 并重建（云服务器）

```bash
nano /docker/Xboard/compose.yaml
```

只将 `services.xboard.image` 改成 `new_image` 对应的完整地址。保留原有环境变量、端口、卷和 `shared_services` 网络。

当前应保留的挂载：

```yaml
volumes:
  - ./.env:/www/.env
  - ./.docker/.data/:/www/.docker/.data
  - ./storage/logs:/www/storage/logs
  - ./storage/theme:/www/storage/theme
  - ./plugins:/www/plugins
  - redis-data:/data
```

确认没有旧的 `PaymentService.php` 单文件挂载遮盖镜像内代码；当前基线未使用该挂载。以后如曾添加过，应先核对再移除。

检查配置，成功才应用：

```bash
cd /docker/Xboard

docker compose config --quiet \
  && docker compose up -d --no-deps xboard

docker compose ps
docker compose logs --tail=150 xboard
```

此操作会短暂中断 Xboard API、支付通知处理和订阅获取。不需要重新执行 `xboard:install`，也不需要停止 MySQL、Nginx、NY 或 Elegant Proxy。

当前 entrypoint 会执行 `xboard:update`。若 Redis 尚未通过可达性检查，会临时用 array/sync 驱动执行更新；该消息本身不能判断最终 Redis 状态。脚本可能在更新失败后继续启动，所以容器显示 Up 不代表迁移完成，必须检查日志。

### 5.5 检查进程并刷新 Nginx 上游解析（云服务器）

```bash
docker exec xboard-xboard-1 \
  supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status
```

应启用的服务没有反复退出，应用启动正常后执行：

```bash
docker exec infra-nginx nginx -t \
  && docker exec infra-nginx nginx -s reload
```

Xboard 容器重建可能改变 IP；这里重新加载 Nginx，使 `xboard` 上游名称重新解析。

## 6. 上线验收

### 6.1 核对镜像与配置（云服务器）

```bash
docker inspect xboard-xboard-1 --format '{{.Config.Image}}'

docker exec xboard-xboard-1 \
  sed -n '74,110p' /www/app/Services/PaymentService.php

docker exec xboard-xboard-1 cat /www/config/payment.php

docker exec -w /www xboard-xboard-1 \
  php artisan tinker --execute='dump(admin_setting("app_url")); dump(config("payment.theme_origins"));'
```

单 Theme 部署预期：默认地址为 `https://yiyunplus.com`，额外来源列表可为空。后台“站点 URL / App URL”不能留空，否则缺少有效来源的请求无法获得完整的默认返回地址。

如需设置默认地址，优先在 Xboard 后台系统配置中修改。也可以执行：

```bash
docker exec -w /www xboard-xboard-1 \
  php artisan tinker --execute='admin_setting(["app_url" => "https://yiyunplus.com"]); dump(admin_setting("app_url"));'
```

这里修改的是后台设置，不是 `.env` 中的 `APP_URL`。后台设置可能还被 Xboard 其他功能使用，保存后同时检查相关业务。

### 6.2 浏览器与客户端验收

- Xboard 管理后台正常，ElegantTheme 用户能登录。
- 套餐、余额、流量、到期时间及订单列表正常。
- 原有订阅和新的 Proxy 订阅都能更新，节点能实际连接。
- 新建一笔小额订单并支付，浏览器返回对应 Theme 的 `/dashboard/order/?id=订单号`。
- 面板异步通知验签成功，订单状态及套餐/余额确实更新。仅页面跳回成功不能证明支付成功。
- 观察一段正常业务周期后再清理旧镜像和旧备份。

此前生成的支付请求可能已保存旧返回地址，测试必须使用新订单。

## 7. 回滚

### 7.1 仅回滚应用镜像

先判断数据库迁移是否与旧版本兼容。仅在兼容时按下面恢复应用；有破坏性迁移时不要直接启动旧代码。

重新连接终端后，手动指定本次更新前的实际备份目录：

```bash
backup_dir='/docker/backups/xboard-替换为实际备份时间'
cat "$backup_dir/rollback-image.txt"
```

恢复原 Compose，并把 `services.xboard.image` 改为上述文件中的独立回滚标签：

```bash
cd /docker/Xboard
cp -a "$backup_dir/compose.yaml" compose.yaml
nano compose.yaml
```

如本次没有保存 `rollback-image.txt`，但本机仍有旧镜像，可以先执行：

```bash
docker image tag "$(cat "$backup_dir/previous-image-id.txt")" xboard-yiyun:rollback-recovery
```

然后将 Compose 的 image 填为 `xboard-yiyun:rollback-recovery`。这依赖旧镜像还在本机。

```bash
docker compose config --quiet \
  && docker compose up -d --no-deps xboard

docker compose logs --tail=150 xboard
```

确认应用正常后重新加载 Nginx。不要重新 pull 一个可能已变化的 `latest` 作为回滚版本。

`.env` 不默认覆盖回旧备份；如需恢复，应比较后只恢复必要项，避免丢失升级后新增的有效配置。

### 7.2 涉及数据库恢复

暂停相关写入，保留故障时的数据库快照，判断备份之后产生的订单及用户数据如何处理，再制定恢复方案。不要直接导入旧 SQL 覆盖当前生产库。旧镜像不一定能读取新版数据库，恢复数据库也不等于恢复全部挂载文件。

## 8. 多个 Theme 域名

以下第二个域名 `https://app2.yiyunplus.com` 仅为示例，未表示已经部署。

### 8.1 默认站点与额外来源的关系

| 配置 | 单 Theme | 多 Theme |
| --- | --- | --- |
| Xboard 后台 `app_url` | `https://yiyunplus.com` | 仍选一个默认地址，例如 `https://yiyunplus.com` |
| Xboard `.env` 的 `PAYMENT_THEME_ORIGINS` | 不需要填 | 填额外 Theme 来源，逗号分隔 |
| Proxy `security.allowedOrigins` | 一个 Theme 来源 | 列出全部允许的 Theme 来源 |
| Proxy `subscription.theme_origin` | 一个 Theme 来源 | 登记全部需要执行订阅操作的 Theme 来源 |
| Proxy `subscription.allowed_hosts` | 实际上游订阅主机名 | 不因增加 Theme 域名就添加前端主机名 |

`app_url` 已自动进入支付白名单，无需在额外来源中重复。所有来源使用精确值：协议、域名和非默认端口；不带页面路径、查询参数及末尾斜线。

### 8.2 先准备新域名的 DNS、HTTPS 和静态站点

1. 新域名指向正确源站，配置 HTTPS，并提供同套 `www/` 静态文件。
2. 当前使用 Cloudflare Origin 证书时，访问域名保持橙云代理，使用 Full (strict)。现有 `*.yiyunplus.com` 只覆盖一层子域名，不覆盖其他根域名或多层子域名。
3. Nginx 用户端 HTTPS server 需接受新域名；HTTP 跳转也应保留所访问的域名，避免所有入口都强制回到主域名。不要改变现有 www 的规范化规则，除非有明确需要。
4. `/login/`、`/dashboard/` 等静态导出目录可以直接访问，未知路由返回 404。
5. 新站点继续请求当前同套 Proxy 地址；如果要更换 Proxy 或构建绑定的上游地址，按发布包另行处理。

修改 Nginx 配置后，在云服务器测试并加载，成功后用浏览器确认新域名能打开正确的静态站点：

```bash
docker exec infra-nginx nginx -t \
  && docker exec infra-nginx nginx -s reload
```

### 8.3 配置 Xboard（云服务器）

编辑实际环境文件，不需要为了这件事修改 `.env.example`：

```bash
nano /docker/Xboard/.env
```

例如：

```dotenv
PAYMENT_THEME_ORIGINS=https://app2.yiyunplus.com
```

有多个额外来源时：

```dotenv
PAYMENT_THEME_ORIGINS=https://app2.yiyunplus.com,https://app3.yiyunplus.com
```

只填实际由自己管理、已部署的 HTTPS Theme 来源。这些值是支付返回白名单，不能填 `*`，也不应填写支付网关或 Proxy 管理地址。

清除可能存在的 Laravel 配置缓存，再重启 Xboard，使常驻进程加载配置：

```bash
cd /docker/Xboard

docker exec -w /www xboard-xboard-1 php artisan config:clear \
  && docker compose restart xboard
```

检查启动日志。单纯编辑挂载的 `.env` 后执行 `docker compose up -d`，不保证现有容器及常驻进程重新加载文件。

验证：

```bash
docker exec -w /www xboard-xboard-1 \
  php artisan tinker --execute='dump(admin_setting("app_url")); dump(config("payment.theme_origins"));'
```

该功能已在当前镜像中，增加额外来源通常不需要重新构建代码。

### 8.4 配置 Elegant Proxy

在 Proxy 后台修改对应运行配置，或编辑实际使用的 `/docker/elegant/middleware/runtime.yaml`。合并到已有字段，不重复创建整段配置。

**当前发布包使用字段名 `security.allowedOrigins`。线上教程某些文字写作 `allowed_origins`，不要直接将下划线字段加入本包 YAML；以本包模板和后台支持的字段为准。**

```yaml
security:
  # 保留当前 trusted_proxies、rateLimit 等字段。
  allowedOrigins:
    - "https://yiyunplus.com"
    - "https://app2.yiyunplus.com"
```

启用订阅中转时，订阅操作的 Theme 来源也需要登记。当前文档支持多个来源，例如多行字符串：

```yaml
subscription:
  # 保留 enabled、public_url、allowed_hosts 等其他已有字段。
  theme_origin: |
    https://yiyunplus.com
    https://app2.yiyunplus.com
```

保存后按后台提示重启：

```bash
cd /docker/elegant
docker compose restart proxy
docker compose logs --tail=80 proxy
```

Proxy 只会把符合其允许来源规则的浏览器 Origin 作为 `X-Elegant-Theme-Origin` 传给 Xboard；Xboard 再做自己的精确白名单校验。旧版 Proxy 若不提供该头，会回退到默认 `app_url`。因此两端都要配置，不能仅修改一侧。

新增 Theme 域名不需要因此修改 `subscription.allowed_hosts`：它控制 Proxy 能访问的上游订阅主机名。`custom_domains`、`preferred_domain` 是客户端订阅接入域名，也不等同于 Theme 域名。

### 8.5 逐域名验证

1. 分别从主 Theme 和新 Theme 登录并创建新订单。
2. 从新 Theme 发起支付，确认返回同一个新 Theme 的订单详情页，而不是总回主站。
3. 验证异步到账、订单列表、订阅生成和客户端订阅更新。
4. 如果支付总回默认站点，检查 Proxy 版本、浏览器 Origin、Proxy 允许来源、Xboard 实际白名单、配置缓存及是否已重启。
5. 不同域名的浏览器登录状态通常独立；不要把登录令牌拼到支付返回 URL 中实现跨域登录。

## 9. 常见问题

| 现象 | 检查方向 |
| --- | --- |
| Git push 提示密码不支持 | 用 IDEA GitHub 浏览器授权、合适令牌或 SSH，不能用账户密码 |
| 合并上游后自有功能丢失 | 检查第 2 节五个文件及冲突处理记录 |
| 镜像构建成功但没有最新修改 | 核对触发提交、`SOURCE_COMMIT`、构建日志和部署 digest；检查是否有旧单文件挂载 |
| 拉取镜像 denied / unauthorized | GHCR 包可见性及当前账号的拉取权限 |
| 容器 Up，但接口异常 | 查看 `xboard:update`、数据库、Redis 和 Supervisor 日志 |
| 重建后 Nginx 502 | 确认应用已监听且网络仍为 shared_services，再测试并 reload Nginx |
| `admin_setting("app_url")` 为 null | 设置后台默认 Theme 根地址，不能只改 `.env` 的 APP_URL |
| 新域名支付回到主站 | 两端白名单、来源头及配置重新加载情况 |
| 返回页面正确但订单未到账 | 检查网关异步通知、验签和订单处理；return_url 不决定付款成功 |
| 新版仍返回旧路由 | 用新订单测试，检查支付插件是否覆盖 return_url，确认新代码与 digest 已生效 |

## 10. 每次发布的简短记录

建议在自己的发布记录中填写：

```text
发布时间：
上游提交：
自有提交：
目标镜像 digest：
更新前镜像 / 回滚标签：
配置和数据库备份位置：
数据库迁移及兼容性说明：
登录 / 订单 / 支付返回 / 异步到账 / 订阅验收结果：
```

公共仓库不记录密码、用户身份、完整订阅链接或服务器私钥。无需在公开发布记录中附带数据库备份内容。

## 11. 参考资料

- 上游 Compose 部署与更新：<https://github.com/cedar2025/Xboard/blob/master/docs/en/installation/docker-compose.md>
- Elegant 支付返回与多 Theme 来源：<https://docs.elegant.wiki/zh-CN/usage/theme/payment-return>
- Elegant 运行配置：<https://docs.elegant.wiki/zh-CN/usage/proxy/runtime-config>
- Elegant 静态站点部署：<https://docs.elegant.wiki/zh-CN/usage/theme/static-site>
- GitHub 手动运行 Actions：<https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow>

以上资料可能随版本变化；本文按本次部署所用代码和发布包整理。升级时先核对实际源码，尤其是 entrypoint、支付服务、支付插件和 Proxy 配置字段。
