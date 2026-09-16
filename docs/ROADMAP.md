# PayFlow · 路线图（H1）

> 版本节奏：H1 = 立项季度。对齐 OpenFlow 版本节奏（当前 v2.6）。

## H1 · 独立跑通（核心里程碑：一笔真实收款全闭环）

- [ ] 独立代码库：PHP 8.3 + JSON 数据层（与 OpenFlow 同栈，便于日后并入）
- [ ] 嵌入式结账 SDK：checkout.js（data-product 属性 → 托管收银台）
- [ ] 商品/价格管理：一次性 + 订阅两种定价
- [ ] 支付通道 ×2：支付宝当面付 / 微信 Native（按 PaymentChannel 适配层扩展）
- [ ] 订单状态机：created → paid → delivered → refunded
- [ ] 简单会员：购买即会员（权益挂内容 URL 白名单）
- [ ] 通知：收款/退款邮件（SMTP 直发）

## H2 · 裂变与结算

- [ ] 推荐码体系：code→点击→归因→注册/订单归属
- [ ] 佣金策略 + 余额 + 提现审核
- [ ] 优惠券：满减/折扣/限时
- [ ] 市场上架：数字商品交付（license/文件）
- [ ] 数据看板：GMV/订阅流失/渠道佣金

## H2+ · 打磨

- [ ] 试用期/首单折扣/升级降级
- [ ] Webhook 出站（订单事件给 UserLoop/任何 MA）
- [ ] 与 LearnFlow 联调：课程售卖场景
- [ ] 发票/收据

## 技术约束（与 OpenFlow 一致）

- PHP 8.3、SQLite（兼容 3.7.17 降级）、无框架、零 composer 运行时依赖
- 域名：payflow.nownexts.com（服务器 /www/wwwroot/payflow）
- 部署/rsync/GitHub/CF/AI 配置见 `docs/` 同名文档（从 OpenFlow 同步来的副本）
