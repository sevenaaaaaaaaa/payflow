# PayFlow · 商业变现引擎

> 芭乐派产品矩阵成员（P1 候补 → 立项）。定位 brief 见 `docs/POSITIONING.md`，路线图见 `docs/ROADMAP.md`。

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

- Webs Flow 落地页挂 PayFlow 结账（表单→支付闭环）
- 订单事件回传 UserLoop（全域档案）
- MFlow 分发的内容挂 PayFlow 购买链接
- 对 OpenFlow：想升级全家桶时，PayFlow 数据平滑并入

## 状态

- [x] 立项 + 定位（本文档）
- [x] 产品页上线（nownexts.com/payflow）
- [x] 独立代码库搭建（H1）→ `payflow/`
- [x] 首批支付通道跑通（H1）→ manual 端到端跑通；支付宝/微信适配层就绪待配凭证

## 线上入口

**https://nownexts.com/payflow** = **后台专属入口**（登录 / 管理）。产品页、能力页等
营销内容归 nownexts.com 主站的统一二级目录（如 `nownexts.com/product/payflow`），不放在本应用内。

- 已部署：2026-09-17，服务器 `/www/wwwroot/payflow`（nownexts.com vhost 用 `Alias /payflow /www/wwwroot/payflow` 挂载）
- 后台登录：用户名 + 密码（凭据见 `docs/secrets-local.md`；服务器 `data/config.json → admin.password_hash`，`bin/passwd.php` 可改密）
- Cloudflare：已加 `/payflow` 绕过缓存规则，避免登录态 HTML 被边缘缓存
- 主站 `.htaccess`：`^payflow/?$` 原 301 到产品页，已改为「真实子目录优先」（备份见 `docs/secrets-local.md`）

- 配置：`app.base_url=https://nownexts.com/payflow`、`app.base_path=/payflow`
- 挂载：nownexts.com docroot 下的 `payflow/` 子目录，自带 `.htaccess`（路由自动剥离 `/payflow` 前缀）
- 后台：`/payflow`（= 概览，未登录跳登录页）· 商品 `/payflow/admin/products` · 订单 `/payflow/admin/orders`
- 演示店铺（demo）：`/payflow/store`——只是本应用的演示站，**不是**主站的产品/能力页
- 客户侧（买家可见，留在 `/payflow` 下）：`/payflow/checkout`、`/payflow/pay/{token}`、`/payflow/d/{token}`
- 嵌入代码：`<script src="https://nownexts.com/payflow/checkout.js" data-product="...">`
- 回调：`https://nownexts.com/payflow/notify/{alipay,wechat}`

## 已内置能力（H1 + H2）

- 结账：一次性 / 订阅、优惠券、推荐归因、多通道（人工 / 支付宝 / 微信 / 加密货币）
- 收款形态：商品结账、临时支付链接（任意金额 `/l/{token}`）、兑换券（免费领取 `/redeem`）
- 订阅：自动续费、失败重试（1/3/5 天）、宽限期降级、到期提醒
- 裂变：推荐码 → 点击 → 归因 → 佣金（冻结/解冻/冲正）；大使层级
- 结算：佣金余额、提现申请/审批/打款、推荐人自助页 `/partner`
- 交付：文件托管 + 签名限时/限次下载、License 密钥、发卡库存（自动发卡密）
- 开放：API Key/HMAC 对外 API（`/api/v1/*`，见 `docs/API.md`）、Webhook 出站（HMAC + 重试）、发票/收据
- 看板：GMV、转化漏斗、订阅健康（MRR/流失）、渠道分布、佣金；订单搜索/分页/CSV 导出；操作审计
- 存储：MySQL 主库（`pf_records`）→ SQLite 辅助回退 → JSON 兜底；`bin/migrate.php` 幂等迁移

## 定时任务（生产）

```bash
php payflow/bin/cron.php   # 订阅续费/提醒 + 佣金解冻 + Webhook 重试
# 建议每 15 分钟一次（见 docs/DEPLOY.md）
```

## 本地跑起来

```bash
php payflow/bin/seed.php --dev          # 写入 dev 配置 + 演示商品
php -S 127.0.0.1:8787 -t payflow payflow/index.php
# 后台 http://127.0.0.1:8787/ （= /admin，令牌 payflow-dev） · 演示店铺 /store
php payflow/tests/run.php               # 自检（19 项）
```

## 代码结构

```
payflow/
├── index.php              # 前端控制器（Apache vhost 根）
├── bootstrap.php          # 自动加载 + 配置深合并（data/config.json 覆盖）
├── config/app.php         # 默认配置（密钥不入库，见 docs/PAYMENT-CHANNELS.md）
├── routes/web.php         # 路由表
├── src/
│   ├── Http/              # Request/Response/Router/AdminAuth + Controller
│   ├── Store/             # JSON 存储引擎（flock + 原子替换）
│   ├── Domain/            # 商品/订单/客户/订阅/权益/事件 + 订单状态机
│   ├── Payment/           # 通道适配层（manual/alipay/wechat）+ RSA2/APIv3 签名
│   └── Service/           # 结账编排/权益/邮件/Webhook
├── views/                 # 收银台/支付/交付/后台（走设计令牌）
├── public/assets/         # checkout.js 嵌入 SDK + tokens.css/modules.css 快照
├── tests/run.php          # 自检
└── data/                  # 运行时数据（gitignored，服务器为源）
```

## 文档

- 定位 `docs/POSITIONING.md` · 路线图 `docs/ROADMAP.md` · 通道配置 `docs/PAYMENT-CHANNELS.md`
- 设计规范 `docs/DESIGN-SYSTEM.md` · 部署 `docs/DEPLOY.md` · Cloudflare `docs/CLOUDFLARE.md`
