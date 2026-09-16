# LearnFlow · Cloudflare 配置（同步自 OpenFlow）

## 已为本项目做好的配置

| 项 | 值 |
|---|---|
| DNS | `A learnflow.nownexts.com → <SERVER_IP>`（已代理 proxied: true） |
| 边缘证书 | Universal SSL 自动覆盖 `*.nownexts.com`（无需单独申请） |
| SSL 模式 | 继承 zone 配置 `full`（非 strict——源站证书主机名不匹配也可接受） |
| 回源 | 443 → Apache vhost `learnflow.nownexts.com`（现有证书即可） |

## Zone 信息（与 OpenFlow 同 zone）

- Zone ID：`<CF_ZONE_ID>`（账号 NowX，账户 ID 与 R2 endpoint 前缀一致）
- API Token：见 `docs/secrets-local.md`（gitignored）

## 常用操作（与 OpenFlow 一致）

```bash
# 清理指定 URL 缓存
curl -s -X POST -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{"files":["https://learnflow.nownexts.com/","https://learnflow.nownexts.com/assets/xxx.css"]}' \
  "https://api.cloudflare.com/client/v4/zones/$ZONE/purge_cache"

# 全站清缓存（慎用）
curl -s -X POST -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" --data '{"purge_everything":true}' \
  "https://api.cloudflare.com/client/v4/zones/$ZONE/purge_cache"
```

## 静态资源（R2 + Workers）约定

OpenFlow 的静态资产走 `r2-assets` Worker（`assets/*`）、视频走 `r2-media` Worker
（`media/*`，支持 Range/206 分段）。LearnFlow 若需要同样的资产链路：

1. 复用现有桶 `nownexts-static`（键前缀建议 `payflow/assets/`），或自建桶
2. 复用 r2-media Worker 逻辑：键前缀已固定为 `media/`，视频放 `media/payflow/xxx.mp4`
3. Workers 部署脚本在 OpenFlow 仓 `deploy/`（`deploy-r2-media.py`、`upload-r2-video.py`）

## 注意

- 高频 curl 会被 CF 挑战页拦截（浏览器正常）——自动化验证时注意
- `.env`、`data/` 已被服务器 Apache 拒绝直连（沿用 OpenFlow .htaccess 规则）
