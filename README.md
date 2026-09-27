<div align="center">

# PayFlow —— 独立开发者与小微卖家的收款单件

**收款 + 订阅 + 老带新返佣 + 佣金结算，一行 `<script>` 嵌进任何页面，三分钟开始收钱。**

![Language](https://img.shields.io/badge/Language-PHP%208%2B-blue)
![Version](https://img.shields.io/badge/Version-1.0.7-green)
![Tests](https://img.shields.io/badge/%E8%87%AA%E6%A3%80-137%20%E9%A1%B9-brightgreen)
![License](https://img.shields.io/badge/License-MIT-2563eb.svg)

[官网](https://nownexts.com) · [在线后台](https://nownexts.com/payflow) · [使用指南](docs/USAGE-GUIDE.md) · [功能总附录](docs/APPENDIX-FEATURES.md) · [GitHub](https://github.com/sevenaaaaaaaaa/payflow)

</div>

---

## 这是什么

你做出了一个能卖的东西——电子书、模板、软件授权、训练营席位。就差「能收钱」这一步：装一套电商系统太重；直接对接支付平台太烦，签约、证书、回调、对账、发货，每一环都得工程师盯。很多产品就卡死在这最后一步。

PayFlow 把收钱这一步做成一个独立小产品。一行嵌入代码贴进任意页面——落地页、博客、静态站都行，页面原地不动——它就是一个能用的收银台：一次性付款和订阅都可以，支持优惠券，老客户推荐能拿返佣，付完款自动把货发给买家，每一笔钱在后台看得清清楚楚。你不搬家、不迁数据，收钱的事归它管。

它是产品矩阵里的**进阶层**：先有产品要卖，才有收钱这件事。单独可用，不需要先装任何 CMS 或增长平台；哪天你用上了 [OpenFlow](https://github.com/sevenaaaaaaaaa/openflow) 全家，它随时接上——互通只走公开 API 和事件，拔掉任何一个，其余照常。生产已上线（2026-09-17 起，当前 1.0.7），并与 [LearnFlow](https://github.com/sevenaaaaaaaaa/learnflow) 完成联调：买课即入学，退款即撤权。

一笔钱从哪来到哪去，一图看懂：

```
你的任意页面（落地页 / 博客 / 静态站）
   │  <script src=".../checkout.js" data-product="prod_xxx">
   ▼
PayFlow 结账（优惠券 · 推荐归因 · 一次性/订阅）
   ▼
支付通道（人工确认 / 支付宝 / 微信 / 加密货币）
   ▼
发货（文件下载 · License 密钥 · 发票）  ──►  邮件通知买家
   ▼
看板（GMV / 转化 / 订阅续费 / 渠道 / 佣金）
   ▼
事件推送（Webhook 签名 + 重试）──► LearnFlow / 你的其他系统
```

## 核心能力

- **一行嵌入收银台** —— `<script src=".../checkout.js" data-product="prod_xxx">` 贴进任何页面就能收款。嫌嵌代码麻烦？后台点一下生成专属支付链接，发微信里就能卖；还有弹窗、兑换券、卡密发卡多种形态。
- **订阅扣费有人管** —— 到期自动生成续费单并邮件提醒，扣款失败按 1/3/5 天自动重试，宽限期后再降级。续费率在后台直接看。
- **老带新返佣** —— 每位买家可拿到自己的推荐链接；有人通过链接下单，佣金先冻结再解冻，提现走你审批。推荐人打开 `/partner` 就能看到自己的业绩和推广链接，不用你手工对账。
- **虚拟货自动发货** —— 文件托管 + 签名限时下载链接，License 密钥自动发放，发票自动生成。卖虚拟货不再需要拼三个第三方工具。
- **优惠券与兑换券** —— 满减、折扣、限量，创建到核销后台全记录；兑换券适合做赠品和售后补偿。
- **四种收款通道，通道即插件** —— 人工确认（默认启用，零资质）/ 支付宝（RSA2）/ 微信（APIv3）/ 加密货币。接新通道不改业务代码。
- **对外开放** —— API Key 调 `/api/v1` 管商品、订单、订阅、客户；每个关键动作对外发 Webhook（HMAC 签名 + 失败重试）。后台有经营看板、订单导出、操作审计、按 Key 限流。

## 真实界面

**① 演示店铺** —— 商品挂上来就是这个样子；真实场景里你甚至不需要这一页，嵌入代码贴在自己的页面即可。

![演示店铺](docs/screenshots/01-home.png)

**② 结账页** —— 填邮箱、选支付方式、付钱，三步走完；付完货自动发出，邮件同时送达。

![结账页](docs/screenshots/04-checkout.png)

**③ 专属支付链接** —— 后台点一下生成，发朋友圈、发微信群，点开即付，适合临时卖货和一对一收款。

![支付链接](docs/screenshots/02-link.png)

**④ 推荐人中心** —— 你的推荐人打开自己的链接，业绩、佣金、提现入口都在这一页，不用你手工对账。

![推荐人中心](docs/screenshots/03-partner.png)

**⑤ 管理后台** —— 概览一屏看清 GMV、订单、订阅、客户；商品、优惠券、佣金、审计各有专页。

![管理后台](docs/screenshots/05-admin.png)

## 具体用例

**用例 A：电子书作者，三天开始收钱。**
① 后台建商品，上传 PDF 与配套模板；② 把嵌入代码贴进自己的落地页；③ 用默认的「人工确认」通道收款，订单进来自动发下载链接，收到转账点一下「确认」即可。等支付宝/微信签约下来，切换通道不用改页面。

**用例 B：训练营按年收订阅费。**
① 建「订阅」类型商品，年付 ¥1999；② 学员付款后权益即时生效，明年到期前 7 天自动发续费邮件；③ 学员卡里没钱？系统按 1/3/5 天重试并给宽限期，你不用追着催。

**用例 C：让老学员帮你卖。**
① 后台「推荐」页给老学员建档，把 `/partner` 自助页链接发给他；② 他分享自己的推广链接，有人下单佣金自动记账，先冻结（防退款套利）再解冻；③ 学员在自助页申请提现，你审批后线下打款、标记已付。全程有审计记录。

## 快速开始

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

打开那个页面，点购买按钮走完一笔测试订单，回后台看订单与看板。自检与发布审计：`php payflow/tests/run.php`（137 项）· `php payflow/bin/audit.php`。生产部署与定时任务见 [docs/DEPLOY.md](docs/DEPLOY.md)：`php payflow/bin/cron.php` 建议每 15 分钟跑一次（订阅续费与提醒、佣金解冻、Webhook 重试）。

线上形态参考：<https://nownexts.com/payflow>（后台入口），同一套代码按 Host 自适应子路径或独立域名部署：

| 入口 | 路径 |
|---|---|
| 后台（登录 / 管理） | `/payflow`（未登录跳登录页）· 商品 `/payflow/admin/products` · 订单 `/payflow/admin/orders` |
| 客户侧 | 结账 `/payflow/checkout` · 支付 `/payflow/pay/{token}` · 下载 `/payflow/d/{token}` · 临时支付链接 `/l/{token}` · 兑换 `/redeem` |
| 大使 | 推荐计划 `/partner` |
| 回调 | `/payflow/notify/{alipay,wechat,crypto}` |

## 开源开放

- **核心功能永久开源**，MIT 协议，与 [OpenFlow](https://github.com/sevenaaaaaaaaa/openflow) 一致。收款、订阅、返佣、交付、后台——你在这里看到的全部能力，都在开源版里。
- 开发者可以据此收获商业成功，但那属于**定制化开发与服务**，与开源版无关，我们不做功能阉割逼你买商业版。
- **支持矩阵开源生态**：互通只走公开 API 与事件，欢迎把 PayFlow 接进任何系统——拔掉任何一个，其余照常。
- **支持开发者按需开发插件**：支付通道本身就是插件结构，欢迎为新通道、新交付形态、新看板贡献插件，完善所有人体验。

## 与 OpenFlow 的关系

PayFlow 属于进阶层：卖数字产品的人要的不是增长系统，是「能收钱」——装它一个就够，不需要先有任何 CMS、CDP 或增长平台。

- 想在落地页/内容页收款 → 嵌入代码即可，与页面是什么技术栈无关。
- 已在用矩阵其他产品 → 互通走公开 API，不绑定：Webs Flow 落地页挂 PayFlow 结账（表单即支付）；订单事件回传 UserLoop 进全域档案；MFlow 分发的内容挂 PayFlow 购买链接；LearnFlow 购买即入学、退款撤销权益（正向 Webhook + 反向入站事件，已联调）。
- 已在用 OpenFlow → 想并入时，PayFlow 数据经统一事件信封 / Outbox 增量拉取平滑对接，不做一次性迁移。

互通原则：只走公开 API（HMAC / API Key）与事件，不共享数据库；拔掉任何一个，其余照常。

## 当前边界（诚实声明）

- 支付宝 / 微信通道代码就绪，但真实商户凭证联调未完成——当前生产以人工确认通道端到端跑通（1.0.7 状态）。
- 订阅免密代扣需要商户协议，还没有；加密货币的链上自动到账需要外部资质，当前人工确认到账。
- 佣金打款走人工审批，不做自动放款；拼团这类资金玩法不在 PayFlow 范围内。
- 支付凭证、商户私钥一律不入库（`config/app.php` 只存默认结构，运行时配置在 `data/config.json`，已 git-ignore）。

我们区分**已实现 / 已接入 / 已被使用 / 已验证有效**，不把远景写成现状。

## 文档

[使用指南](docs/USAGE-GUIDE.md) · [功能总附录](docs/APPENDIX-FEATURES.md) · [API](docs/API.md) · [支付通道配置](docs/PAYMENT-CHANNELS.md) · [矩阵互通](docs/ECOSYSTEM.md) · [事件目录](docs/EVENTS.md) · [部署](docs/DEPLOY.md) · [变更日志](CHANGELOG.md)

## License

[MIT](https://github.com/sevenaaaaaaaaa/openflow/blob/main/LICENSE) · 由 [芭乐派](https://nownexts.com) 维护 —— 增长方法论与增长社区。
