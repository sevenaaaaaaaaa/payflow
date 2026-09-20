# PayFlow · 矩阵互通与数据流规划

> 定位：矩阵的**收款域 + 订单事实源 + 现金流中枢**。不与任何产品共享数据库，一切互通走公开
> API 与事件（HMAC/API Key）。拔掉任一产品，其余照常——互通是增益，不是依赖。

## 一、已具备的互通基座

| 能力 | 现状 |
|---|---|
| 出站 API | `/api/v1/meta`（能力清单）、`products`、`checkout`、`orders/{no}`、`coupons/validate`、`licenses/validate`、`analytics/summary` |
| 鉴权 | API Key：Bearer（`key_id.secret`）或 HMAC-SHA256（时间戳 5 分钟防重放） |
| 出站事件 | 12 个事件（见 `docs/EVENTS.md`），HMAC 签名 + 退避重试（最多 5 次）+ 后台可重发 |
| 入站事件 | `POST /api/v1/events`：`entitlement.revoke` / `customer.update` / `customer.upsert`；LearnFlow 退课别名 |
| 增量拉取 | `GET /api/v1/events?since=`（Outbox 游标，不依赖 webhook 可达） |
| 统一主体 | `subject{email,external_id,tenant}`：结账、嵌入 SDK、入站、客户/订单落库与后台检索 |
| 幂等 | 订单状态机 `created→paid→delivered→refunded`；`markPaid` 幂等；入站 `idempotency_key` |
| 免登录链接 | 交付页 `/d/{token}`、发票 `/invoice/{token}`、下载 `/download/{token}`、推荐人自助 `/partner?token=`、临时支付链接 `/l/{token}` |
| 治理 | 每 Key 分钟/日配额、沙箱 Key、弃用头、API 错误率入看板 |
| 存储 | MySQL 主库（`pf_records`）+ FULLTEXT 检索；SQLite/JSON 回退 |

## 二、数据流场景（谁赋能谁）

| 场景 | 上游 → 下游 | 通道 | 价值 |
|---|---|---|---|
| 表单即支付 | Webs Flow 承接页 → PayFlow | `POST /api/v1/checkout` + `subject` | 承接页不必自建支付 |
| 付费用户入档 | PayFlow → UserLoop | `order.paid` / `order.delivered` webhook | 全域档案补上「付费金额/RFM/首复购」 |
| 内容带货归因 | MFlow 内容 → PayFlow | 购买链接 + `referral` 参数 | 内容→成交闭环，佣金可结算 |
| 课程售卖/开通 | LearnFlow → PayFlow；PayFlow → LearnFlow | `checkout` + `order.delivered` webhook | 收款即开课；退款即回收学籍 |
| 分群定向收款 | UserLoop 分群 → PayFlow | `/l/{token}` 支付链接（定向/限次） | 私域/召回直接收款，结果回流分群 |
| 线索成交 | OpenFlow Sales → PayFlow | 支付链接 + `order.paid` | 销售驾驶舱看到真实回款 |
| 情报→定价 | inFlow 情报 → PayFlow | `analytics/summary` + 人工/建议 | 依据趋势调价/发券 |
| 全家桶平滑并入 | PayFlow → OpenFlow（可选） | 事件 + 增量拉取 | 想升级整套时数据不丢 |

## 三、契约与发现（H3.1–H3.3，已落地）

- [x] `GET /api/v1/meta` 能力清单（版本、端点、事件、通道、鉴权、主体、入站类型）
- [x] `docs/EVENTS.md` 事件目录
- [x] 统一事件信封 `{ id, type, version, occurred_at, source, subject, data, idempotency_key }`（兼容旧 `event/sent_at`）
- [x] `GET /api/v1/version` 与弃用策略（Deprecation/Sunset/Link 头）
- [x] `POST /api/v1/events` 入站（HMAC + 幂等）：撤销权益、客户更新/建档
- [x] `GET /api/v1/events?since=` 增量拉取
- [x] 统一主体 `subject{ email, external_id, tenant }`
- [x] 限流（每 Key 每分钟 + 每日，超限 429 + `Retry-After` / `X-RateLimit-*`）
- [x] 沙箱：test Key + 强制人工通道 + `mode=test` 事件，看板排除
- [x] 日配额与 API 错误率（看板 + `/api/v1/analytics/summary`）

## 四、已联调：PayFlow ↔ LearnFlow（课程售卖）

| 项 | 值 |
|---|---|
| 通道 | PayFlow 出站 Webhook → `POST https://nownexts.com/learnflow/api/payflow-webhook.php` |
| 鉴权 | `X-PayFlow-Signature = hex(HMAC_SHA256(secret, body))`（两端共享密钥） |
| 触发事件 | `order.paid`（入学）；`order.delivered` 被安全忽略 |
| 映射 | LearnFlow 课程字段 `payflow_product_id` = PayFlow 商品 `id` |
| 载荷 | 统一信封；订单字段在 `data` 下，含 `product_id / order_no / email / amount_number / amount_cents / coupon_code / ref_code` |

配置位置：
- PayFlow：`data/config.json → webhooks.order` 或 `webhooks.endpoints`
- LearnFlow：`data/settings.json → payflow = { enabled, base_url, secret, api_key }`

**联调验证（2026-09-18）**：下单支付 → Webhook 200 → LearnFlow 学籍建立（幂等）。

**反向通道（已通）**：LearnFlow `enroll_remove` → `POST /api/v1/events`（`entitlement.revoke` 或 `learnflow.enrollment.cancelled`，Bearer + 幂等键）→ PayFlow 撤销对应订单权益。
- 也可只带 `subject.external_id` / `subject.email`，按客户撤销其有效权益。

**多目标投递**：`webhooks.endpoints = [{name,url,secret,enabled,events}]`，兼容旧 `webhooks.order` 单目标。

## 五、互通不变量（不可协商）

1. 每个产品独立可用；互通是增益。
2. 不共享数据库，只走公开 API/事件。
3. 每个对象有稳定 ID、版本、时间；事件有幂等键。
4. 模型/AI 只能提议，**执行事实只能来自系统**。
5. 金额、订单、佣金以系统事实为准，可对账、可审计。
6. 版本演进向后兼容；破坏性变更走新版本 + 迁移说明。
