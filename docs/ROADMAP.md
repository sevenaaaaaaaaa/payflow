# PayFlow · 路线图（H1）

> 版本节奏：H1 = 立项季度。对齐 OpenFlow 版本节奏（当前 v2.6）。

## H1 · 独立跑通（核心里程碑：一笔真实收款全闭环）

- [x] 独立代码库：PHP 8.3 + JSON 数据层（与 OpenFlow 同栈，便于日后并入）
  → `payflow/`（前端控制器 `index.php` + `src/` + `views/`，零 composer 运行时依赖）
- [x] 嵌入式结账 SDK：checkout.js（data-product 属性 → 托管收银台）
  → `public/assets/checkout.js`（自动按钮 / data-target 挂载 / PayFlow.open / 弹窗与跳转两形态）
- [x] 商品/价格管理：一次性 + 订阅两种定价
  → `ProductRepository` + 后台 `/admin/products` CRUD
- [x] 支付通道 ×2：支付宝当面付 / 微信 Native（按 PaymentChannel 适配层扩展）
  → 适配层 `src/Payment/Channel/`（manual / alipay / wechat 三个实现，`docs/PAYMENT-CHANNELS.md`）
  ⚠️ manual 已端到端跑通；alipay/wechat 代码与验签就绪，**真实商户凭证待配置联调**
- [x] 订单状态机：created → paid → delivered → refunded
  → `OrderStateMachine` + 订单内嵌轨迹 + `events` 全局审计
- [x] 简单会员：购买即会员（权益挂内容 URL 白名单）
  → `EntitlementService` + `/api/entitlement` 白名单校验 + `/d/{token}` 交付页
- [x] 通知：收款/退款邮件（SMTP 直发）
  → `Mailer`（ssl/tls + AUTH LOGIN）+ `Notifier` 模板
- [x] 自检：`php payflow/tests/run.php`（状态机/金额/RSA2/微信 AES-GCM/JSON 存储，19 项通过）

## H2 · 裂变与结算

- [x] 推荐码体系：code→点击→归因→注册/订单归属 → `ReferralService` + `/r/{code}` 归因 Cookie
- [x] 佣金策略 + 余额 + 提现审核 → `CommissionService`（冻结/解冻/冲正）+ `PayoutService`（申请/审批/打款）+ `/partner` 自助页
- [x] 优惠券：满减/折扣/限时 → `CouponService` + 后台 CRUD + 收银台即时校验
- [x] 市场上架：数字商品交付（license/文件）→ `DeliveryService`（文件上传 + 签名限次下载 + License 密钥）
- [x] 数据看板：GMV/订阅流失/渠道佣金 → `/admin/analytics`（GMV/转化/续费/佣金/渠道）+ CSV 导出

## H2+ · 打磨

- [x] 试用期/首单折扣/升级降级 → 试用期字段 + 优惠券；订阅宽限期降级
- [x] Webhook 出站（订单事件给 UserLoop/任何 MA）→ `WebhookDispatcher`（HMAC + 退避重试 + 后台可见）
- [ ] 与 LearnFlow 联调：课程售卖场景
- [x] 发票/收据 → `InvoiceService` + 签名链接 `/invoice/{token}`
- [x] 开放 API（API Key/HMAC）→ `/api/v1/*`，见 `docs/API.md`
- [x] 订阅续费引擎（自动续费/失败重试/宽限期降级）→ `SubscriptionService` + `bin/cron.php`

## H3 · 互通与自进化

> 详细规划见 `docs/ECOSYSTEM.md`（矩阵互通与数据流）与 `docs/EVOLUTION.md`（自我进化机制）；
> 唯一施工图见 `docs/BACKLOG.md`。

- [x] 能力清单 `GET /api/v1/meta` + 事件目录 `docs/EVENTS.md`
- [x] 自审计工具 `bin/audit.php`（质量/性能/入口覆盖，CI 可调用）
- [x] 统一事件信封（id/version/subject/idempotency_key）
- [x] 入站事件 `POST /api/v1/events` + 增量拉取 `GET /api/v1/events?since=`
- [x] 统一主体 subject{email,external_id,tenant}（结账可带，客户/订单落库）
- [ ] 限流配额 / 沙箱 / 弃用策略
- [x] `bin/propose.php`（AI 提议，人工批准）
- [ ] L2 受控自动执行

## 技术约束（与 OpenFlow 一致）

- PHP 8.3、MySQL 主库（SQLite 辅助回退，JSON 兜底）、无框架、零 composer 运行时依赖
- 域名：入口 `https://nownexts.com/payflow`（服务器 `<docroot>/payflow`，子路径挂载）
- 部署/rsync/GitHub/CF/AI 配置见 `docs/` 同名文档（从 OpenFlow 同步来的副本）
