<div align="center">

# PayFlow

**商业变现引擎：收款 + 订阅 + 推荐裂变 + 佣金结算，一行嵌入任何页面**

![Language](https://img.shields.io/badge/Language-PHP%208%2B-blue)
![Version](https://img.shields.io/badge/Version-1.0.7-green)
![Tests](https://img.shields.io/badge/%E8%87%AA%E6%A3%80-140%2B%20%E9%A1%B9-brightgreen)
![License](https://img.shields.io/badge/License-%E7%A7%81%E6%9C%89%E9%A1%B9%E7%9B%AE-lightgrey)

[官网](https://nownexts.com) · [使用指南](docs/USAGE-GUIDE.md) · [相关仓库](https://github.com/sevenaaaaaaaaa)

</div>

## 这是什么

你做了个数字产品——课程、模板、软件授权、咨询——就差「能收钱」这一步。装一套电商系统？太重；直接对接支付平台原生 API？证书、回调、对账、退款全是坑。PayFlow 给你一行 `<script>`：贴进任何页面就是一个能用的收银台，订阅、优惠券、推荐返佣全配套，三分钟开始收钱。

它不是又一个建店工具。你不用把内容搬进它的商店——页面留在原地（任何 CMS / 落地页 / 静态页都行），收款逻辑归它管：一次性与订阅结账、续费失败重试、文件签名下载交付、推荐码归因、佣金冻结解冻提现，每一笔在后台看得清清楚楚。与其他系统互通只走公开 API 和事件，不共享数据库，不绑架你的业务栈。

生产已上线（2026-09-17 起，当前版本 1.0.7），并与 LearnFlow 完成课程售卖联调：购买即入学、退款撤销权益。自检脚本内置 140+ 项检查，发布走自审计门禁。

一条购买链路一图看懂：

```
你的任意页面（落地页 / CMS / 内容页 / 静态站）
   │  <script src=".../checkout.js" data-product="prod_xxx">
   ▼
PayFlow 结账（优惠券 · 推荐归因 · 一次性/订阅）
   ▼
支付通道（人工确认 / 支付宝 / 微信 / 加密货币）
   ▼
交付（文件签名下载 · License 密钥 · 发票）  ──►  通知（邮件）
   ▼
看板（GMV / 转化 / 订阅健康 / 渠道 / 佣金）
   ▼
事件出站（Webhook HMAC + 重试）──► UserLoop / LearnFlow / 你的任意系统
```

## 核心能力

- **一行嵌入收银台** —— `<script src=".../checkout.js" data-product="prod_xxx">` 贴进任何页面即收款；另有托管结账页、弹窗、临时支付链接 `/l/{token}`、兑换券 `/redeem`、发卡库存多种形态。
- **订阅全闭环** —— 到期自动建续费单 + 邮件支付链接，失败按 1/3/5 天重试，宽限期降级，订阅健康看板直接看续费率。
- **推荐裂变与佣金结算** —— 推荐码归因、大使层级、佣金冻结/解冻/冲正、提现审批与打款、`/partner` 大使页、佣金对账单 CSV，每一步可审计。
- **数字交付** —— 文件托管 + 签名限时/限次下载、License 密钥发放、发票 `/invoice/{token}`，卖虚拟货不需要再拼第三方工具。
- **优惠券与折扣码** —— 创建、展示、核销、透传（给下游矩阵产品）全链路。
- **多支付通道适配层** —— 人工确认 / 支付宝（RSA2）/ 微信（APIv3）/ 加密货币，通道即插件，扩展新渠道不改业务代码。
- **开放 API 与 Webhook** —— API Key（Bearer/HMAC）+ `/api/v1/*`（商品/订单/订阅/客户），出站 Webhook 多目标（HMAC 签名 + 重试），入站事件幂等；统一主体 `subject{email, external_id, tenant}` 贯穿结账、嵌入 SDK、入站事件、后台检索。
- **经营看板与治理** —— GMV、转化、订阅健康、渠道、佣金看板；订单搜索/分页/CSV 导出；API 按 Key 分钟/日配额、CSRF、沙箱、操作审计。

## 快速上手

```bash
# 依赖：PHP 8+（无框架、无 composer 运行时依赖）
git clone https://github.com/sevenaaaaaaaaa/payflow.git && cd payflow

php payflow/bin/seed.php --dev               # 写入 dev 配置 + 演示商品
php -S 127.0.0.1:8787 -t payflow payflow/index.php
# 后台 http://127.0.0.1:8787/（admin / payflow-dev）
# 演示店铺 http://127.0.0.1:8787/store
```

第一件事：在后台建一个商品，然后把嵌入代码贴进任意 HTML 页面——

```html
<script src="http://127.0.0.1:8787/checkout.js"
        data-product="prod_xxx"
        data-external-id="user_1"></script>
```

打开那个页面，点购买按钮走完一笔测试订单，回后台看订单与看板。自检与审计：`php payflow/tests/run.php` · `php payflow/bin/audit.php`（发布门禁）。生产部署与定时任务见 [docs/DEPLOY.md](docs/DEPLOY.md)：`php payflow/bin/cron.php` 建议每 15 分钟一次（订阅续费/提醒、佣金解冻、Webhook 重试、用量清理）。

线上形态参考：<https://nownexts.com/payflow>（后台入口），同一套代码按 Host 自适应子路径或独立域名部署：

| 入口 | 路径 |
|---|---|
| 后台（登录 / 管理） | `/payflow`（未登录跳登录页）· 商品 `/payflow/admin/products` · 订单 `/payflow/admin/orders` |
| 客户侧 | 结账 `/payflow/checkout` · 支付 `/payflow/pay/{token}` · 下载 `/payflow/d/{token}` · 临时支付链接 `/l/{token}` · 兑换 `/redeem` |
| 大使 | 推荐计划 `/partner` |
| 回调 | `/payflow/notify/{alipay,wechat,crypto}` |

## 与 OpenFlow 的关系

PayFlow 属于进阶层：卖数字产品的人要的不是增长系统，是「能收钱」——装它一个就够，不需要先有任何 CMS、CDP 或增长平台。

- 想在落地页/内容页收款 → 嵌入代码即可，与页面是什么技术栈无关。
- 已在用矩阵其他产品 → 互通走公开 API，不绑定：Webs Flow 落地页挂 PayFlow 结账（表单即支付）；订单事件回传 UserLoop 进全域档案；MFlow 分发的内容挂 PayFlow 购买链接；LearnFlow 购买即入学、退款撤销权益（正向 Webhook + 反向入站事件，已联调）。
- 已在用 OpenFlow → 想并入时，PayFlow 数据经统一事件信封 / Outbox 增量拉取平滑对接，不做一次性迁移。

互通原则：只走公开 API（HMAC / API Key）与事件，不共享数据库；拔掉任何一个，其余照常。

## 使用指南

完整使用指南见 **[docs/USAGE-GUIDE.md](docs/USAGE-GUIDE.md)**。

深入文档：[API](docs/API.md) · [支付通道配置](docs/PAYMENT-CHANNELS.md) · [矩阵互通](docs/ECOSYSTEM.md) · [事件目录](docs/EVENTS.md) · [部署](docs/DEPLOY.md) · [Cloudflare 配置](docs/CLOUDFLARE.md) · [定位 brief](docs/POSITIONING.md) · [路线图](docs/ROADMAP.md) · [变更日志](CHANGELOG.md)。

代码结构速览（部署只同步 `payflow/`，排除 `data/`、`uploads/`）：

```
payflow/
├── index.php / bootstrap.php   # 前端控制器 + 自动加载（data/config.json 深合并）
├── config/app.php              # 默认配置与版本号（密钥不入库）
├── src/
│   ├── Http/                   # Request / Response / Router / AdminAuth
│   ├── Store/                  # MySQL 主库 → SQLite 辅助 → JSON 兜底
│   ├── Domain/                 # 商品 / 订单 / 客户 / 订阅 / 权益 / 事件 / 用量
│   ├── Payment/                # 通道适配层 + RSA2 / APIv3
│   └── Service/                # 结账 / 权益 / 邮件 / Webhook / 入站 / 限流
├── public/assets/              # checkout.js + 设计令牌
├── bin/                        # cron / audit / migrate / seed
└── tests/run.php               # 自检（140+ 项）
```

## 当前边界

- 支付宝 / 微信通道代码就绪，但真实商户凭证联调未完成——当前生产以人工确认通道端到端闭环（1.0.7 状态）。
- 订阅免密代扣待商户协议；加密货币的链上自动监听需外部资质，当前人工确认到账。
- 佣金打款走人工审批，不做自动放款；资金域动作（拼团等）不在 PayFlow 单机闭环内。
- 支付凭证、商户私钥一律不入库（`config/app.php` 只存默认结构，运行时配置在 `data/config.json`，已 git-ignore）。

## License

未附带开源许可证，当前为私有项目。如需授权使用，请联系 [nownexts.com](https://nownexts.com)。
