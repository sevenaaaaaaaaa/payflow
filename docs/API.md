# PayFlow · 开放 API（v1）

> 对外 API 用于矩阵互通（UserLoop / Webs Flow / MFlow）与自建集成。鉴权用 API Key，
> 与主站/其它产品**不共享数据库**，只走 HTTP。

## 鉴权

在后台「API」页新建密钥，得到 `key_id` 与 `secret`（secret 只显示一次）。

### 方式一：HMAC（推荐）

```
X-PF-Key: <key_id>
X-PF-Timestamp: <unix 秒，与服务端差 ≤ 300s>
X-PF-Signature: hex( HMAC_SHA256( secret, timestamp + "\n" + METHOD + "\n" + PATH + "\n" + BODY ) )
```

- `PATH` 为含挂载前缀的请求路径（如 `/payflow/api/v1/orders/PF2026...`），不含查询串。
- `BODY` 为原始请求体，GET 为空串。

### 方式二：Bearer

```
Authorization: Bearer <key_id>.<secret>
```

## 限流与沙箱

- 限流：每 Key 每分钟请求上限（默认 120，`config.api.rate_limit`）。超限返回 **429** + `Retry-After` + `X-RateLimit-*`。
- 沙箱：后台新建「沙箱」密钥；用沙箱 Key 下单会**强制人工通道**、订单标记 `test=true`、
  事件信封 `mode=test` 且带 `X-PayFlow-Mode: test`。看板统计**排除**沙箱订单。

## 端点

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/v1/products` | 在售商品列表 |
| POST | `/api/v1/checkout` | 创建订单并返回支付链接（body: product, email, name, channel?, coupon?, referral?） |
| GET | `/api/v1/orders/{orderNo}` | 查询订单 |
| GET/POST | `/api/v1/coupons/validate` | 优惠券试算（product, code, email?） |
| GET | `/api/v1/analytics/summary?days=30` | 经营汇总（GMV/漏斗/订阅/佣金/渠道） |
| GET | `/api/v1/version` | 版本与弃用面 |
| GET | `/api/v1/events?since=&limit=` | 增量拉取出站事件（Outbox；游标为上一批最后 `occurred_at`） |
| POST | `/api/v1/events` | 入站事件（`idempotency_key` 去重；支持 `entitlement.revoke` / `customer.update`） |
| GET/POST | `/api/v1/licenses/validate` | 校验 License 密钥（body/query: license_key） |

返回统一 JSON：成功 `{"ok":true,...}`，失败 `{"ok":false,"error":"..."}`（HTTP 401/404/422）。

## 出站 Webhook

配置 `data/config.json → webhooks.order = {enabled,url,secret}`。所有订单/订阅/佣金/提现事件
以 JSON 推送，签名头 `X-PayFlow-Signature = hex(HMAC_SHA256(secret, body))`，`X-PayFlow-Event` 为事件名。

事件：`order.paid` `order.delivered` `order.refunded` `subscription.renewed` `subscription.payment_failed`
`subscription.canceled` `commission.pending` `commission.available` `commission.reversed`
`payout.requested` `payout.paid` `referral.click`。

失败按 30s/1m/2m/4m…（最长 1h）退避重试，最多 5 次；后台「Webhook」页可查看并手动重发。

## curl 示例（HMAC）

```bash
KEY=pfk_xxx
SECRET=yyy
TS=$(date +%s)
PATH_=/payflow/api/v1/products
SIG=$(printf '%s\nGET\n%s\n' "$TS" "$PATH_" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.*= //')
curl -s "https://nownexts.com$PATH_" \
  -H "X-PF-Key: $KEY" -H "X-PF-Timestamp: $TS" -H "X-PF-Signature: $SIG"
```
