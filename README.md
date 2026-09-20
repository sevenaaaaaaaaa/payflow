# PayFlow · 商业变现引擎

> 当前版本 **1.0.7**（`config/app.php`，对外 `GET /api/v1/meta`）。芭乐派产品矩阵成员。
> 定位 brief 见 `docs/POSITIONING.md`，施工图见 `docs/BACKLOG.md`。

## 一句话定位

收款 + 订阅 + 推荐裂变 + 佣金结算，**一行嵌入任何页面**——不要求你用任何特定的 CMS 或 CDP。

## 独立化三门槛（已过）

1. **独立人群**：知识付费创作者、独立开发者、课程讲师——只要有「能收钱」，不要增长系统
2. **零母体依赖**：买按钮嵌进任何落地页/内容页/第三方网站即可收款，不依赖 OpenFlow CMS/CDP
3. **数据模型级体量**：订单/订阅/佣金提现是独立完备的数据域，插件装不下

## 能力域（OpenFlow 现成底子 → PayFlow 独立化）

| 能力 | OpenFlow 来源模块 | PayFlow 独立形态 |
|---|---|---|
| 购物车/结账 | CartSystem | 嵌入式结账（buy 按钮 / 弹窗 / 托管页） |
| 订阅计费 | SubscriptionSystem | 定期订阅 + 自动续费 + 试用期 |
| 优惠券 | CouponSystem | 券/折扣码管理 |
| 推荐裂变 | ReferralSystem | 推荐码 + 大使层级 |
| 佣金结算 | CommissionPolicy | 分销佣金 + 提现审核 |
| 市场上架 | MarketplaceSystem | 数字商品上架与交付 |
| 会员权益 | MembershipSystem | 会员等级与内容解锁 |
| 支付通道 | PaymentChannel | 通道适配层（扩展多支付渠道） |

## 与矩阵的互通（API，不绑定）

- Webs Flow 落地页挂 PayFlow 结账（表单→支付闭环，可带 `subject`）
- 订单事件回传 UserLoop（全域档案）
- MFlow 分发的内容挂 PayFlow 购买链接
- LearnFlow：收款即开课，退课即撤销权益（正向 Webhook + 反向入站事件）
- 对 OpenFlow：想升级全家桶时，PayFlow 数据经事件/增量拉取平滑并入

互通不共享数据库，只走公开 API / 事件。详见 `docs/ECOSYSTEM.md`。

## 状态（1.0.7）

- [x] H1 独立全闭环：结账 / 订阅 / 交付 / 会员 / 退款 / 通知（manual 端到端）
- [x] H2 裂变与结算：推荐佣金提现、优惠券、数字交付、发票、看板
- [x] H3 互通基座：统一事件信封、入站事件、Outbox 增量拉取、统一主体
- [x] 治理：每 Key 分钟/日配额、沙箱、弃用头、CSRF、API 错误率看板
- [x] LearnFlow 课程售卖联调（开课 + 退课撤销）
- [ ] 支付宝/微信真实凭证联调、订阅免密代扣、链上监听（需外部资质）
- [ ] L2 受控自动执行

## 线上入口

**https://nownexts.com/payflow** = **后台专属入口**（登录 / 管理）。产品页、能力页等
营销内容归主站统一二级目录（`https://nownexts.com/product/payflow`），不放在本应用内。

同一套代码同时服务两个入口（按 Host 自适应 `base_path`）：

| 入口 | base_path |
|---|---|
| https://nownexts.com/payflow | `/payflow` |
| https://payflow.nownexts.com | 空 |

- 已部署：2026-09-17 起；当前生产 **1.0.7**（2026-09-20）
- 服务器：`/www/wwwroot/payflow`（nownexts.com vhost 用 `Alias /payflow` 挂载）
- 后台登录：用户名 + 密码（凭据见 `docs/secrets-local.md`；服务器 `data/config.json → admin.password_hash`，`bin/passwd.php` 可改密）
- Cloudflare：`/payflow` 绕过缓存，避免登录态 HTML 被边缘缓存
- 后台：`/payflow`（概览，未登录跳登录页）· 商品 `/payflow/admin/products` · 订单 `/payflow/admin/orders`
- 演示店铺：`/payflow/store`（本应用演示站，**不是**主站产品页）
- 客户侧：`/payflow/checkout`、`/payflow/pay/{token}`、`/payflow/d/{token}`、`/l/{token}`、`/redeem`
- 嵌入：

```html
<script src="https://nownexts.com/payflow/checkout.js"
        data-product="prod_xxx"
        data-external-id="user_1"
        data-tenant="learnflow"></script>
```

也可 `PayFlow.open({ product, external_id, tenant, email })`。
- 回调：`https://nownexts.com/payflow/notify/{alipay,wechat,crypto}`

## 已内置能力

- 结账：一次性 / 订阅、优惠券、推荐归因、多通道（人工 / 支付宝 / 微信 / 加密货币）
- 主体：`subject{email, external_id, tenant}` 贯穿结账、嵌入 SDK、入站事件、后台检索
- 收款形态：商品结账、临时支付链接 `/l/{token}`、兑换券 `/redeem`、发卡库存
- 订阅：到期建续费单 + 邮件/支付链接、失败重试（1/3/5 天）、宽限期降级（免密代扣待商户协议）
- 裂变结算：推荐码归因、大使层级、佣金冻结/解冻/冲正、提现审批/打款、`/partner`、佣金对账单 CSV
- 交付：文件托管 + 签名限时/限次下载、License 密钥、发票 `/invoice/{token}`
- 开放：API Key（Bearer/HMAC）、`/api/v1/*`（见 `docs/API.md`）、Webhook 多目标出站（HMAC + 重试）、入站事件（幂等）
- 看板：GMV、转化、订阅健康、渠道、佣金；API 请求/错误率；订单搜索/分页/CSV；操作审计
- 存储：MySQL 主库（`pf_records`）→ SQLite 辅助 → JSON 兜底；`bin/migrate.php` 幂等迁移

## 定时任务（生产）

```bash
php payflow/bin/cron.php
# 订阅续费/提醒 + 佣金解冻 + Webhook 重试 + 限流/用量/事件清理
# 建议每 15 分钟一次（见 docs/DEPLOY.md）
```

## 本地跑起来

```bash
php payflow/bin/seed.php --dev          # 写入 dev 配置 + 演示商品
php -S 127.0.0.1:8787 -t payflow payflow/index.php
# 后台 http://127.0.0.1:8787/ （admin / payflow-dev） · 演示店铺 /store
php payflow/tests/run.php               # 自检
php payflow/bin/audit.php               # 自审计（发布门禁）
```

## 代码结构

```
PayFlow Dev/               # git 仓库根（文档 + 应用）
├── README.md · CHANGELOG.md · docs/
└── payflow/               # 应用根（rsync 到 /www/wwwroot/payflow/）
    ├── index.php          # 前端控制器
    ├── bootstrap.php      # 自动加载 + data/config.json 深合并
    ├── config/app.php     # 默认配置（密钥不入库）
    ├── routes/web.php
    ├── src/
    │   ├── Http/          # Request/Response/Router/AdminAuth + Controller
    │   ├── Store/         # MySQL / SQLite / JSON
    │   ├── Domain/        # 商品/订单/客户/订阅/权益/事件/用量
    │   ├── Payment/       # 通道适配层 + RSA2 / APIv3
    │   ├── Service/       # 结账/权益/邮件/Webhook/入站/限流
    │   └── Support/       # Subject / Money / View / …
    ├── views/
    ├── public/assets/     # checkout.js + 设计令牌
    ├── bin/               # cron / audit / propose / migrate / seed
    ├── tests/run.php
    └── data/              # 运行时（gitignored，服务器为源）
```

部署只同步 `payflow/`，排除 `data/`、`uploads/`。详见 `docs/DEPLOY.md`。

## 文档

- 定位 `docs/POSITIONING.md` · 路线图 `docs/ROADMAP.md` · 施工图 `docs/BACKLOG.md`
- 矩阵互通 `docs/ECOSYSTEM.md` · 事件目录 `docs/EVENTS.md` · 自我进化 `docs/EVOLUTION.md`
- 通道配置 `docs/PAYMENT-CHANNELS.md` · API `docs/API.md` · 变更 `CHANGELOG.md`
- 设计规范 `docs/DESIGN-SYSTEM.md` · 部署 `docs/DEPLOY.md` · Cloudflare `docs/CLOUDFLARE.md`
