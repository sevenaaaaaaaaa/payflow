# PayFlow · 施工图（Backlog）

> 「接下来建什么」的唯一来源。优先级：🔴 地基/架构债 · 🟢 攻（差异化） · 🟦 守（必需）。
> 状态：⬜ 未做 · 🟡 部分 · ✅ 已做。审计结果由 `php payflow/bin/audit.php` 产生。

## 已完成（截至 2026-09-18）

- ✅ H1 全闭环：结账/订阅/交付/会员/退款/通知（manual 通道端到端）
- ✅ H2：订阅续费引擎、优惠券、推荐裂变+佣金+提现、数字交付+License、数据看板、发票
- ✅ 收款形态：临时支付链接、兑换券、发卡库存、加密货币通道
- ✅ 开放能力：API Key（Bearer/HMAC）、`/api/v1/*`、Webhook 出站（重试）、`/api/v1/meta`
- ✅ 存储：MySQL 主库 + SQLite 辅助 + JSON 兜底；查询/聚合/搜索全面下推；FULLTEXT(ngram)
- ✅ 工程：CI（PHP 8.3/8.4）、`bin/audit.php` 自审计、102 项自检、双入口（nownexts.com/payflow 与 payflow.nownexts.com）

## T0 · 地基（互通与自进化）

| # | 事项 | 类 | 状态 | 说明 |
|---|---|---|---|---|
| 1 | 统一事件信封（id/version/subject/idempotency_key） | 🔴 | ✅ | 出站已按信封发送，兼容旧字段 |
| 2 | 入站事件 `POST /api/v1/events`（HMAC+幂等） | 🔴 | ✅ | 支持 entitlement.revoke / customer.update |
| 3 | 增量拉取 `GET /api/v1/events?since=` | 🔴 | ✅ | Outbox + 游标 |
| 4 | `bin/propose.php`（AI 提议，人工批准） | 🟢 | ✅ | 读审计+指标 → DeepSeek → `docs/PROPOSALS.md` |
| 5 | 统一主体 `subject{email,external_id,tenant}` | 🔴 | 🟡 | 结账可带 external_id/tenant，客户/订单已落库 |

## T1 · 治理与体验

| # | 事项 | 类 | 状态 |
|---|---|---|---|
| 6 | 每 Key 限流（429） | 🟦 | ✅ |
| 7 | 沙箱（test Key + manual 通道 + mode=test） | 🟦 | ✅ |
| 8 | 版本面 + 弃用头（`X-API-Version`/`Deprecation`/`Sunset`/`Link`） | 🟦 | ✅ |
| 9 | 审计页服务端分页 | 🟢 | ✅ |
| 10 | 后台写操作 CSRF token | 🟦 | ✅ |

## T2 · 增长

| # | 事项 | 类 | 状态 |
|---|---|---|---|
| 11 | 订阅免密代扣协议（支付宝周期扣款/微信委托代扣） | 🟢 | ⬜ |
| 12 | 支付通道真实凭证联调（支付宝/微信） | 🟢 | ⬜ |
| 13 | 加密货币链上监听服务 | 🟢 | ⬜ |
| 14 | 与 LearnFlow 课程售卖联调 | 🟢 | 🟡 | PayFlow→LearnFlow 开课已通；反向退课撤销待做 |
| 15 | 佣金结算报表 / 对账单导出 | 🟢 | ⬜ |
