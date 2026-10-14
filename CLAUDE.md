# CLAUDE.md — PayFlow Dev

## 项目简介

PayFlow（芭乐 Pay，林下 Understory 出品）：面向独立开发者与小微卖家的收款单件——一行 `<script>` 嵌入收银台，支持收款、订阅、老带新返佣、佣金结算、支付链接、卡密/兑换券、对外 API（`/api/v1`）与 Webhook。线上后台入口 https://nownexts.com/payflow 。远端 `github.com/sevenaaaaaaaaa/payflow`（private），分支 `main`。

## 技术栈

- PHP 8.3+ 无框架（无 composer 运行时依赖），前端控制器 `payflow/index.php`
- 存储：生产 MySQL（库 `payflow`，表 `pf_records`）→ SQLite 回退 → JSON 兜底
- 支付通道：支付宝 / 微信 / 加密货币 / 手动；静态资源可走 Cloudflare R2
- 宝塔 Apache（`.htaccess`），通过 `Alias /payflow` 挂在 nownexts.com 下

## 目录结构

- `payflow/` — 应用根（部署到服务器的就是这一层）
  - `index.php`、`bootstrap.php`、`config/app.php`（只含默认结构）、`routes/`、`src/`（Domain/Http/Payment/Service/Store/Support）、`views/`
  - `public/assets/` — `checkout.js` 与设计资产
  - `bin/` — seed / cron / schema / migrate / audit / passwd / serve.sh / sync-assets-r2.py
  - `tests/run.php` — 自检（130+ 项）
  - `data/` — 运行时数据与配置（gitignored，服务器为源）
- `docs/` — 部署、API、支付通道、事件、生态互通等文档；仓库根 README/docs 只上 GitHub，不同步到服务器

## 常用命令

```bash
# 本地开发（无需安装依赖）
bash payflow/bin/serve.sh                     # seed dev 配置 + php -S 127.0.0.1:8787
# 或
php payflow/bin/seed.php --dev
php -S 127.0.0.1:8787 -t payflow payflow/index.php

# 测试 / 审计（同 CI）
php payflow/tests/run.php
php payflow/bin/audit.php
find payflow -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null   # lint

# 运维
php payflow/bin/schema.php     # 结构迁移（幂等）
php payflow/bin/cron.php       # 定时任务：续费/提醒、佣金解冻、Webhook 重试（建议每 15 分钟）
```

build：无。

## 部署方式

- 目标：nownexts.com 所在的宝塔（Apache）服务器（与 OpenFlow 同机，见 `OpenFlow Dev/docs/DEPLOY-CONVENTIONS.md`），目录 `/www/wwwroot/payflow`，由主站 vhost `Alias /payflow /www/wwwroot/payflow` 挂载；备选子域 `payflow.nownexts.com`。
- CI 不部署（`.github/workflows/ci.yml` 在 push `main`/PR 时跑 lint + 敏感文件守卫 + audit + 自测）。
- **唯一入口（双轨）**：`bash deploy/sync.sh [-m "提交信息"] [--server-only] [--dry-run]`
  - GitHub 轨（公开仓库，只放干净代码）：php lint + `tests/run.php` → `git add -A`（`.gitignore` 已排除 `data/`、`uploads/`、`.env*`、`secrets*`）→ gitleaks 扫描暂存区（泄漏即中止）→ commit → `git push origin main`；无变更则跳过 commit
  - 服务器轨（按 `.cursor/rules/release-sync.mdc`）：rsync 本地 `payflow/` → `/www/wwwroot/payflow/`，`-rtz`、**不加 `--delete`**、不改属主/权限、不推仓库根 README/docs；排除 `data/`、`uploads/`、`.env*`、`.user.ini`、`secrets*`、`*.sqlite*`/`*.db`/`*.lock`/`*.log`、`*.bak*` 等——服务器上的订单、密钥、`data/config.json` 永不覆盖或删除 → 线上 HTTP 验收
  - `--server-only` 跳过测试与 git；`--dry-run` 只做 `rsync -n` 预演，不提交/推送
  - 脚本开头有安全闸：`payflow/data`、`payflow/uploads`、`docs/secrets-local.md` 若被 git 跟踪则中止
  - 本地 `.git/hooks/pre-commit` 会跑 gitleaks；`docs/PAYMENT-CHANNELS.md:55` 的私钥格式示例已在 `.gitleaksignore` 中豁免
  - 主机/端口沿用同机兄弟项目（`172.96.253.73:28766`），`docs/DEPLOY.md` 中仍为占位符；`docs/DEPLOY.md` 的「rsync 源 = Dev 根 + --delete」写法已过时，以本脚本/cursor 规则为准

  注意：`docs/DEPLOY.md` 写的是以仓库根为 rsync 源，与 cursor 规则冲突；以 cursor 规则为准（不要把 README/docs 灌进应用根）。
- 服务器 crontab：`*/15 * * * * cd /www/wwwroot/payflow && /www/server/php/83/bin/php bin/cron.php >> data/cron.log 2>&1`
- 发布流程：提交 → `git push origin HEAD` → 同步服务器 → 用浏览器打开线上入口端到端核对。

## 注意事项

- 环境变量（仅名称）：`MYSQL_ENABLED`；R2 同步脚本用 `R2_ACCESS_KEY`、`R2_SECRET`。运行时配置（数据库、管理员、支付通道密钥）在 `payflow/data/config.json`（gitignored）。
- 勿读取/提交：`docs/secrets-local.md`、`payflow/data/`、`secrets*`、`*.secret.json`、`.env*`、`*.pem`。
- 本地默认后台账号由 `seed.php --dev` 生成（见 README）。
