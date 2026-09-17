# PayFlow · Cloudflare 配置（同步自 OpenFlow）

## 入口形态：主域名子路径

线上入口为 **`https://nownexts.com/payflow`** = 后台专属入口（不单独开子域）。因此：

| 项 | 值 |
|---|---|
| DNS | 复用 `nownexts.com` 现有 A 记录 → `<SERVER_IP>`（proxied: true），**无需新增 DNS** |
| 边缘证书 | Universal SSL 覆盖 `nownexts.com`，自动生效 |
| SSL 模式 | 继承 zone 配置 `full`（非 strict） |
| 回源 | `443` → `nownexts.com` Apache vhost；vhost 内 `Alias /payflow /www/wwwroot/payflow` 挂载应用 |
| 应用挂载 | `app.base_url=https://nownexts.com/payflow`、`app.base_path=/payflow` |
| 边缘缓存 | 已加缓存规则：`starts_with(http.request.uri.path, "/payflow")` → **Bypass cache**（后台/结账/支付/交付为会话页面，禁止边缘缓存） |

> zone 里有一条 catch-all 缓存规则「Cache Dynamic HTML for Anonymous Visitors」（边缘缓存动态 HTML）。
> 该规则需把 `/payflow` 排除（`and not starts_with(http.request.uri.path, "/payflow")`），否则它会覆盖
> `/payflow` 的 bypass，导致登录态页面被缓存（表现为登录后仍看到登录页）。规则集 ID：
> `e1d4b108650e409daff68bbc3f0f1b82`。

## Zone 信息（与 OpenFlow 同 zone）

- Zone ID：`<CF_ZONE_ID>`（账号 NowX，账户 ID 与 R2 endpoint 前缀一致）
- API Token：见 `docs/secrets-local.md`（gitignored）

## 常用操作（与 OpenFlow 一致）

```bash
# 清理指定 URL 缓存
curl -s -X POST -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" \
  --data '{"files":["https://nownexts.com/payflow/","https://nownexts.com/payflow/assets/xxx.css"]}' \
  "https://api.cloudflare.com/client/v4/zones/$ZONE/purge_cache"

# 全站清缓存（慎用）
curl -s -X POST -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" --data '{"purge_everything":true}' \
  "https://api.cloudflare.com/client/v4/zones/$ZONE/purge_cache"
```

## 静态资源（R2 + Workers）约定

OpenFlow 的静态资产走 `r2-assets` Worker（`assets/*`）、视频走 `r2-media` Worker
（`media/*`，支持 Range/206 分段）。PayFlow 若需要同样的资产链路：

1. 复用现有桶 `nownexts-static`（键前缀建议 `payflow/assets/`），或自建桶
2. 复用 r2-media Worker 逻辑：键前缀已固定为 `media/`，视频放 `media/payflow/xxx.mp4`
3. Workers 部署脚本在 OpenFlow 仓 `deploy/`（`deploy-r2-media.py`、`upload-r2-video.py`）

## 注意

- 高频 curl 会被 CF 挑战页拦截（浏览器正常）——自动化验证时注意
- `.env`、`data/` 已被服务器 Apache 拒绝直连（沿用 OpenFlow .htaccess 规则）
