# Changelog

版本号见 `payflow/config/app.php → app.version`，对外经 `GET /api/v1/meta` 暴露。

## [1.0.3] — 2026-09-18

- 多目标 Webhook：`webhooks.endpoints`（每目标 URL/密钥/事件过滤），兼容单目标；后台展示目标与 endpoint
- LearnFlow 反向通道：退课 → `entitlement.revoke` 入站，PayFlow 撤销权益（幂等）
- PayFlow 订单公开字段补 `product_id` 与数值金额（供映射）
- 自检 118 项

## [1.0.2] — 2026-09-18

- 弃用面：`X-API-Version` + `Deprecation/Sunset/Link` 头（`config.api.deprecations`）
- 审计页服务端分页 + 关键字筛选
- 后台写操作 CSRF 防护（会话令牌，419 拒绝）

## [1.0.1] — 2026-09-18

- 互通：统一事件信封 + Outbox 增量拉取 + 入站事件（幂等）+ `subject.external_id/tenant`
- 自进化：`bin/propose.php`（AI 提议，人工批准）+ 审计接入 CI
- 治理：每 Key 限流（429/`Retry-After`/`X-RateLimit-*`）、沙箱 Key、`GET /api/v1/version`

## [1.0.0] — 2026-09-18

首个可独立运营版本。

### 能力
- 结账：一次性 / 订阅、优惠券、推荐归因、多通道（人工 / 支付宝 / 微信 / 加密货币）
- 收款形态：商品结账、临时支付链接 `/l/{token}`、兑换券 `/redeem`、发卡库存
- 订阅：自动续费、失败重试（1/3/5 天）、宽限期降级、到期提醒（`bin/cron.php`）
- 裂变结算：推荐码归因、大使层级、佣金冻结/解冻/冲正、提现审批/打款、`/partner`
- 交付：文件托管 + 签名限次下载、License 密钥、发票 `/invoice/{token}`
- 开放：API Key（Bearer/HMAC）、`/api/v1/*`（含 `/meta`）、Webhook 出站（重试）、12 类事件
- 看板：GMV/转化/订阅健康/渠道/佣金；订单搜索/分页/CSV 导出；操作审计

### 工程
- 存储：MySQL 主库（`pf_records`）+ SQLite 辅助 + JSON 兜底；查询/聚合/搜索下推；FULLTEXT(ngram)
- 性能：懒连接、主键直查、`firstBy`、`aggregate`、`groupByDay`、`search` 下推
- 质量：CI（PHP 8.3/8.4）、`bin/audit.php` 自审计、102 项自检
- 部署：`nownexts.com/payflow` 与 `payflow.nownexts.com` 双入口（Host 自适应）
- 文档：`docs/ECOSYSTEM.md`（互通）、`docs/EVOLUTION.md`（自进化）、`docs/EVENTS.md`、`docs/BACKLOG.md`
