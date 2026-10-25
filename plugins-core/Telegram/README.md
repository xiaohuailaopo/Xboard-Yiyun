# Telegram 插件

XBoard 的 Telegram Bot 插件，提供用户账号绑定、流量查询、订阅链接获取等功能。

## 功能特性

-   ✅ 工单通知功能（可配置开关）
-   ✅ 支付通知功能（可配置开关）
-   ✅ 用户账号绑定/解绑
-   ✅ 流量使用情况查询
-   ✅ 订阅链接获取
-   ✅ 工单回复支持

## 可用命令

### `/start` - 开始使用

欢迎新用户并显示帮助信息，支持动态配置。

### `/bind` - 绑定账号

使用网站个人中心签发的一次性绑定码，将当前 XBoard 账号绑定到 Telegram 私聊账号。

```
/bind [绑定码]
```

### `/traffic` - 查看流量

查看当前绑定账号的流量使用情况。

### `/getlatesturl` - 获取订阅链接

获取最新的订阅链接。

### `/unbind` - 解绑账号

解绑当前 Telegram 账号与 XBoard 账号的关联。

## 配置选项

### 基础配置

| 配置项       | 类型    | 默认值                                                                                     | 说明                 |
| ------------ | ------- | ------------------------------------------------------------------------------------------ | -------------------- |
| `auto_reply` | boolean | true                                                                                       | 是否自动回复未知命令 |
| `help_text`  | text    | '请使用以下命令：\\n/bind - 绑定账号\\n/traffic - 查看流量\\n/getlatesturl - 获取最新链接' | 未知命令的回复文本   |

### `/start` 命令动态配置

| 配置项                  | 类型 | 说明                     |
| ----------------------- | ---- | ------------------------ |
| `start_welcome_title`   | text | 欢迎标题                 |
| `start_bot_description` | text | 机器人功能介绍           |
| `start_bind_guide`      | text | 未绑定用户的绑定指导     |
| `start_unbind_guide`    | text | 已绑定用户显示的命令列表 |
| `start_bind_commands`   | text | 未绑定用户显示的命令列表 |
| `start_footer`          | text | 底部提示信息             |

### 工单通知配置

| 配置项                 | 类型    | 默认值 | 说明                 |
| ---------------------- | ------- | ------ | -------------------- |
| `enable_ticket_notify` | boolean | true   | 是否开启工单通知功能 |

### 支付通知配置

| 配置项                  | 类型    | 默认值 | 说明                 |
| ----------------------- | ------- | ------ | -------------------- |
| `enable_payment_notify` | boolean | true   | 是否开启支付通知功能 |

## 使用流程

### 新用户使用流程

1. 登录网站，进入个人中心并打开机器人入口。
2. 在机器人私聊中点击 **Start / 开始**，自动提交链接中的一次性绑定码。
3. 收到“绑定成功”后，刷新个人中心查看绑定状态。也可以私聊发送 `/bind [绑定码]`。

绑定码五分钟有效、成功使用一次即失效，重新获取会作废旧码。已绑定账号不再签发新码；同一 Telegram 账号不能绑定多个账户。旧订阅链接不再用于绑定，订阅 token 本身保持不变。

### 日常使用流程

1. 查看流量：`/traffic`
2. 获取订阅链接：`/getlatesturl`
3. 管理绑定：`/unbind`

## Elegant Theme 1.1.8 部署检查

配套要求：Theme v1.1.8、Proxy v2.0.8，参考[官方绑定文档](https://docs.elegant.wiki/zh-CN/usage/proxy/telegram-binding)。

- 发布 `app/Services/ElegantTelegramBinding.php`、`app/Http/Controllers/V1/User/TelegramController.php`、本插件的 `Plugin.php` 和 `config.json`，按原部署方式重载 Octane/机器人处理进程。
- 面板已启用 Telegram 插件并配置有效的 Bot Token、原有 webhook；此次适配不改变 webhook 的地址和验证方式。
- Web API 与 webhook 使用相同的 Redis cache store、连接、数据库及缓存前缀。多实例也必须共享此配置；Redis 故障时不允许降级绕过校验。
- Proxy 配置 `theme.show_telegram_bot: true`，发布路由包含 `/user/telegram/getBotInfo`（XBoard 通常使用 `/api/v1` 前缀）。以实际 `runtime.yaml` 的字段层级为准。
- 插件后台若已保存自定义 `start_bind_guide`、`start_bind_commands` 或 `help_text`，需将其中旧的订阅链接绑定说明改为“从网站个人中心打开机器人并点击开始”；更新文件中的默认值不会覆盖数据库里的自定义文本。
- 登录未绑定测试账号，确认 `getBotInfo` 返回 `data.username` 和 `data.binding_code`，响应含 `Cache-Control: no-store, private`，CDN 不缓存该接口。机器人入口应带 `?start=eg_...`。
- 实际私聊完成绑定，再验证重放、过期、重新获取后旧码、改动字符、已绑定账号、重复 Telegram 账号和群聊均不能覆盖绑定。未登录请求不能签发绑定码。
- 绑定链接代表授权，不要公开、截图或记录到日志。升级面板/插件后重新核对这组改动。

本项目按官方算法实现，使用 `DomainException` 区分可展示的绑定拒绝原因；Redis、数据库等基础设施异常交给原有通用错误处理，避免把内部错误详情直接作为绑定结果发给用户。

### 本地自动化验证

安装锁定依赖后运行（需要 PHP 8.2+、phpredis、pdo_sqlite 和 `redis-server`）：

```bash
composer install --no-interaction --prefer-dist --no-scripts
vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Feature/TelegramBindingTest.php
```

测试自行启动仅监听临时 Unix socket 的 Redis，使用临时 SQLite 数据库，并模拟 Telegram HTTP 请求；不读取 `.env`，不接触线上数据。此验证不能代替生产 MySQL、多实例部署配置及 Telegram 真实私聊验收。
