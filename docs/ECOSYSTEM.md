# PayFlow · 矩阵互通与数据流规划

> 定位：矩阵的**收款域 + 订单事实源 + 现金流中枢**。不与任何产品共享数据库，一切互通走公开
> API 与事件（HMAC/API Key）。拔掉任一产品，其余照常——互通是增益，不是依赖。

## 一、已具备的互通基座

| 能力 | 现状 |
|---|---|
| 出站 API | `/api/v1/meta`（能力清单）、`products`、`checkout`、`orders/{no}`、`coupons/validate`、`licenses/validate`、`analytics/summary` |
| 鉴权 | API Key：Bearer（`key_id.secret`）或 HMAC-SHA256（时间戳 5 分钟防重放） |
| 出站事件 | 12 个事件（见 `docs/EVENTS.md`），HMAC 签名 + 退避重试（最多 5 次）+ 后台可重发 |
| 幂等 | 订单状态机 `created→paid→delivered→refunded`；`markPaid` 幂等；订单号/交易号可对账 |
| 免登录链接 | 交付页 `/d/{token}`、发票 `/invoice/{token}`、下载 `/download/{token}`、推荐人自助 `/partner?token=`、临时支付链接 `/l/{token}` |
| 存储 | MySQL 主库（`pf_records`）+ FULLTEXT 检索；SQLite/JSON 回退 |

## 二、数据流场景（谁赋能谁）

| 场景 | 上游 → 下游 | 通道 | 价值 |
|---|---|---|---|
| 表单即支付 | Webs Flow 承接页 → PayFlow | `POST /api/v1/checkout` | 承接页不必自建支付 |
| 付费用户入档 | PayFlow → UserLoop | `order.paid` / `order.delivered` webhook | 全域档案补上「付费金额/RFM/首复购」 |
| 内容带货归因 | MFlow 内容 → PayFlow | 购买链接 + `referral` 参数 | 内容→成交闭环，佣金可结算 |
| 课程售卖/开通 | LearnFlow → PayFlow；PayFlow → LearnFlow | `checkout` + `order.delivered` webhook | 收款即开课；退款即回收学籍 |
| 分群定向收款 | UserLoop 分群 → PayFlow | `/l/{token}` 支付链接（定向/限次） | 私域/召回直接收款，结果回流分群 |
| 线索成交 | OpenFlow Sales → PayFlow | 支付链接 + `order.paid` | 销售驾驶舱看到真实回款 |
| 情报→定价 | inFlow 情报 → PayFlow | `analytics/summary` + 人工/建议 | 依据趋势调价/发券 |
| 全家桶平滑并入 | PayFlow → OpenFlow（可选） | 事件 + 增量拉取 | 想升级整套时数据不丢 |

## 三、待补齐的互通能力（差距 → 建设）

### H3.1 契约与发现（低风险，先做）
- [x] `GET /api/v1/meta` 能力清单（版本、端点、事件、通道、鉴权）
- [x] `docs/EVENTS.md` 事件目录
- [ ] 统一**事件信封**：`{ id, type, version, occurred_at, source, subject, data, idempotency_key }`
      —— 当前出站事件为 `{event,data,sent_at}`，将向后兼容地补 `id/version/subject`
- [ ] `GET /api/v1/version` 与弃用策略（Deprecation/Sunset 头）

### H3.2 双向通道（核心）
- [ ] `POST /api/v1/events` 入站事件（HMAC + 幂等键）：如 LearnFlow 退课→撤销权益、UserLoop 打标
- [ ] `GET /api/v1/events?since=` 增量拉取（不依赖 webhook 可达性，做最终一致）
- [ ] 重放保护与去重（`idempotency_key` 落库，TTL）

### H3.3 身份与治理
- [x] 统一主体：`subject{ email, external_id, tenant }`（结账可带，客户/订单落库）
- [x] 限流（每 Key 每分钟，超限 429 + `Retry-After`/`X-RateLimit-*`）
- [x] 沙箱：test Key + 强制人工通道 + `mode=test` 事件，看板排除
- [x] 版本/弃用面：`GET /api/v1/version`
- [ ] 日配额、可观测扩展（错误率面板）

## 四、互通不变量（不可协商）

1. 每个产品独立可用；互通是增益。
2. 不共享数据库，只走公开 API/事件。
3. 每个对象有稳定 ID、版本、时间；事件有幂等键。
4. 模型/AI 只能提议，**执行事实只能来自系统**。
5. 金额、订单、佣金以系统事实为准，可对账、可审计。
6. 版本演进向后兼容；破坏性变更走新版本 + 迁移说明。

## 五、H3 排期建议

1. **H3.1**（本次）+ 事件信封字段补全 → 一周
2. **H3.2** 入站事件 + 增量拉取 + 幂等 → 一周
3. **H3.3** external_id/tenant、限流、沙箱 → 一周
