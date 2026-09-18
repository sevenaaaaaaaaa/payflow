# PayFlow · 部署与环境配置（同步自 OpenFlow）

> 从 OpenFlow 主仓同步的配置约定。本项目的具体路径已按下方参数适配。

## 一、服务器

> 入口形态：**`https://nownexts.com/payflow`** = 后台专属入口（主域名子路径，不单独开子域）。
> 产品页/能力页归主站的统一二级目录，不放在本应用内。

| 项 | 值 |
|---|---|
| 主机 | root@<SERVER_IP>，SSH 端口 <SSH_PORT> |
| 面板 | 宝塔（Apache） |
| 本项目目录 | `/www/wwwroot/payflow`（nownexts.com vhost 用 `Alias /payflow /www/wwwroot/payflow` 挂载） |
| 回源 | 复用 nownexts.com 的 vhost（:80 + :443），加 `Alias /payflow /www/wwwroot/payflow` 与对应 `<Directory>`（AllowOverride All） |
| 证书 | 复用 `/www/server/panel/vhost/cert/nownexts.com/`（CF full 模式容忍；边缘 CF Universal SSL 覆盖） |
| PHP CLI | `/www/server/php/83/bin/php`（生产 CLI 报 zip 重复加载警告是已知问题） |
| 应用配置 | `app.base_url=https://nownexts.com/payflow`、`app.base_path=/payflow` |
| 存储 | **MySQL 主库**（`payflow` 库，表 `pf_records`）→ SQLite 辅助回退 → JSON 兜底 |
| 运行时目录 | `data/`（配置 + 旧 JSON 备份 + SQLite 回退库）、`uploads/`（数字交付文件；.htaccess 已阻断直连） |
| 结构迁移 | `php payflow/bin/schema.php`（补 `search_text` 列、回填、建 FULLTEXT(ngram) 索引，幂等） |
| 定时任务 | crontab：`*/15 * * * * cd /www/wwwroot/payflow && /www/server/php/83/bin/php bin/cron.php >> data/cron.log 2>&1` |

> 用 `Alias` 挂载后，`/payflow` 在 URL→文件映射阶段就指向了应用目录，主站 docroot 的
> `.htaccess` router 不再参与，因此互不干扰；产品页 `/product/payflow` 照常。
> 宝塔面板若重建 vhost，需确认 `Alias` 与 `<Directory>` 仍在（或在面板里加「反向代理/别名」）。

### 备选入口（独立子域）

同一套代码同时服务两个入口，应用按 Host 自适应 `base_path`：

| 入口 | base_path | 说明 |
|---|---|---|
| `https://nownexts.com/payflow` | `/payflow` | nownexts.com vhost 的 `Alias /payflow /www/wwwroot/payflow` |
| `https://payflow.nownexts.com` | ``（空） | 独立 vhost，DocumentRoot 直接指向应用根 |

子域 vhost 必须包含以下两项，否则 `.htaccess` 不生效、PHP 会被当静态源码输出：

```apache
<Directory "/www/wwwroot/payflow">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<FilesMatch \.php$>
    SetHandler "proxy:unix:/tmp/php-cgi-83.sock|fcgi://localhost"
</FilesMatch>
```

## 二、代码同步（rsync）

```bash
# 本地 → 服务器（排除运行时数据与密钥）
rsync -az --delete -e "ssh -p <SSH_PORT>" \
  --exclude='.git/' --exclude='data/' --exclude='uploads/' \
  --exclude='vendor/' --exclude='.env' --exclude='.user.ini' \
  --exclude='.DS_Store' --exclude='*.bak*' \
  ./ "root@<SERVER_IP>:/www/wwwroot/payflow/"
```

约定：`data/` 是运行时数据（服务器为源），部署永不删除。

## 三、GitHub

- 仓库：`sevenaaaaaaaaa/payflow`（private，与矩阵兄弟产品一致）
- 首次推送已完成；日常 `git push origin main`
- 凭证：见本机 gitignore 的 `docs/secrets-local.md`（勿入库）

## 四、目录结构约定

```
PayFlow Dev/            # 本地 Dev 根（= git 仓库根）
├── README.md           # 定位 + 能力域 + 状态 + 本地启动
├── docs/
│   ├── POSITIONING.md  # 定位 brief（与矩阵文档同步）
│   ├── ROADMAP.md      # H1/H2 路线图
│   ├── DESIGN-SYSTEM.md
│   ├── PAYMENT-CHANNELS.md # 通道配置与回调地址
│   ├── CLOUDFLARE.md
│   ├── AI-DEEPSEEK.md
│   ├── secrets-local.md   # gitignored，真实密钥放这里
│   └── assets-reference/  # tokens.css / modules.css 快照
└── payflow/            # 代码目录（PHP 前端控制器根）
    ├── index.php       # 前端控制器（/payflow 入口指向此处）
    ├── bootstrap.php · config/ · routes/ · src/ · views/
    ├── public/assets/  # checkout.js + 设计资产
    ├── tests/          # 自检
    └── data/           # 运行时数据（gitignored，服务器为源）
```

服务器对应 `/www/wwwroot/payflow/`；入口 `https://nownexts.com/payflow`，
rsync 源 = 本地 Dev 根。改用独立子域时，把 `app.base_path` 置空、`app.base_url` 换成子域即可。
