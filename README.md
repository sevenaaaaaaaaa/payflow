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
- [ ] 独立代码库搭建（H1）
- [ ] 首批支付通道跑通（H1）
