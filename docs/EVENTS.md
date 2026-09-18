# PayFlow · 事件目录（Webhook / 互通）

> 出站事件以 JSON POST 推送；`X-PayFlow-Event` 为事件名，`X-PayFlow-Signature = hex(HMAC_SHA256(secret, body))`。
> 失败按 30s/1m/2m/4m… 退避重试，最多 5 次；后台「Webhook」页可查看并重发。

## 当前信封

```json
{ "event": "order.paid", "data": { ... }, "sent_at": "2026-09-18T12:00:00+08:00" }
```

## 目标信封（H3.1，向后兼容补字段）

```json
{
  "id": "evt_xxx",
  "type": "order.paid",
  "version": 1,
  "occurred_at": "2026-09-18T12:00:00+08:00",
  "source": "payflow",
  "subject": { "email": "buyer@example.com", "external_id": null, "tenant": null },
  "data": { ... },
  "idempotency_key": "order.paid:PF2026...:1"
}
```

## 事件清单

### 订单
| 事件 | 触发 | data 要点 |
|---|---|---|
| `order.paid` | 支付确认（幂等） | order_no, status, amount_cents, currency, product_name, type, paid_at, email, coupon_code, referral? |
| `order.delivered` | 交付完成（发权益/卡密/发票） | order_no, status, delivered_at, email |
| `order.refunded` | 退款完成 | order_no, status, amount_cents, refunded_at, email |

### 订阅
| 事件 | 触发 | data 要点 |
|---|---|---|
| `subscription.renewed` | 续费支付成功 | subscription_id, order_no, current_period_end |
| `subscription.payment_failed` | 续费失败进入重试 | subscription_id, attempt, next_retry_at |
| `subscription.canceled` | 宽限期降级/手动取消 | subscription_id, reason |

### 佣金 / 提现
| 事件 | 触发 | data 要点 |
|---|---|---|
| `commission.pending` | 订单支付后记佣（冻结） | commission 记录（referral_id, order_no, amount_cents, rate, available_at, status=pending） |
| `commission.available` | 冻结期满解冻（cron） | commission 记录（status=available） |
| `commission.reversed` | 退款冲正 | commission 记录（status=reversed） |
| `payout.requested` | 推荐人申请提现 | payout 记录（referral_id, amount_cents, method, status=requested） |
| `payout.paid` | 提现已打款 | payout 记录（status=paid, transfer_no） |

### 裂变
| 事件 | 触发 | data 要点 |
|---|---|---|
| `referral.click` | 推荐链接被点击 | referral_id, code |

## 消费建议（下游）

- **UserLoop**：`order.paid/delivered/refunded` → 更新全域档案与 RFM；`subscription.*` → 生命周期。
- **LearnFlow**：`order.delivered`（课程商品）→ 开学籍；`order.refunded` → 回收。
- **OpenFlow Sales**：`order.paid` → 线索转成交。
- **任意 MA**：以 `idempotency_key` 去重，失败可回读 `GET /api/v1/orders/{orderNo}` 对账。

## 入站事件（H3.2，规划）

其它产品可推事件给 PayFlow（HMAC + 幂等键）：
`POST /api/v1/events`
```json
{ "type": "learnflow.enrollment.cancelled", "subject": { "email": "..." }, "data": {...}, "idempotency_key": "..." }
```
用途示例：撤销权益、触发退款线索、给订单打标。
