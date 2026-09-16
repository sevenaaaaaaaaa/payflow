# LearnFlow · 部署与环境配置（同步自 OpenFlow）

> 从 OpenFlow 主仓同步的配置约定。本项目的具体路径已按下方参数适配。

## 一、服务器

| 项 | 值 |
|---|---|
| 主机 | root@<SERVER_IP>，SSH 端口 <SSH_PORT> |
| 面板 | 宝塔（Apache） |
| 本项目目录 | `/www/wwwroot/learnflow` |
| Apache vhost | `/www/server/panel/vhost/apache/learnflow.nownexts.com.conf`（:80 + :443） |
| 证书 | 复用 `/www/server/panel/vhost/cert/nownexts.com/`（CF full 模式容忍；边缘由 CF Universal SSL 通配符覆盖） |
| PHP CLI | `/www/server/php/83/bin/php`（生产 CLI 报 zip 重复加载警告是已知问题） |
| SQLite | 服务器 3.7.17（无 FTS5/UPSERT，代码需兼容降级） |

## 二、代码同步（rsync）

```bash
# 本地 → 服务器（排除运行时数据与密钥）
rsync -az --delete -e "ssh -p <SSH_PORT>" \
  --exclude='.git/' --exclude='data/' --exclude='uploads/' \
  --exclude='vendor/' --exclude='.env' --exclude='.user.ini' \
  --exclude='.DS_Store' --exclude='*.bak*' \
  ./ "root@<SERVER_IP>:/www/wwwroot/learnflow/"
```

约定：`data/` 是运行时数据（服务器为源），部署永不删除；缓存清理
`rm -f /www/wwwroot/learnflow/data/cache/*.cache`。

## 三、GitHub

- 仓库：`sevenaaaaaaaaa/learnflow`（private，与矩阵兄弟产品一致）
- 首次推送已完成；日常 `git push origin main`
- 凭证：见本机 gitignore 的 `docs/secrets-local.md`（勿入库）

## 四、目录结构约定

```
PayFlow Dev/            # 本地 Dev 根（= git 仓库根）
├── README.md           # 定位 + 能力域 + 状态
├── docs/
│   ├── POSITIONING.md  # 定位 brief（与矩阵文档同步）
│   ├── ROADMAP.md      # H1/H2 路线图
│   ├── DESIGN-SYSTEM.md
│   ├── CLOUDFLARE.md
│   ├── AI-DEEPSEEK.md
│   ├── secrets-local.md   # gitignored，真实密钥放这里
│   └── assets-reference/  # tokens.css / modules.css 快照
└── payflow/            # 未来代码目录（PHP）
```

服务器对应 `/www/wwwroot/learnflow/`；rsync 源 = 本地 Dev 根。
