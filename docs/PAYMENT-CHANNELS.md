# PayFlow · 支付通道配置

> 通道走 `src/Payment/Channel/` 适配层（实现 `ChannelInterface`）。新增渠道只需实现接口并在
> `config.channels` 注册，结账/订单/交付逻辑零改动。

## 通道清单（H1）

| 通道 | id | 状态 | 说明 |
|---|---|---|---|
| 人工到账 | `manual` | 默认启用 | 无需资质，后台点「确认到账」完成闭环；本地联调/线下收款 |
| 支付宝当面付 | `alipay` | 配置后启用 | `alipay.trade.precreate` 扫码支付 |
| 微信支付 Native | `wechat` | 配置后启用 | APIv3 `/v3/pay/transactions/native` 扫码支付 |
| 加密货币 | `crypto` | 配置后启用 | 静态地址 + 精确金额二维码（USDT-TRC20/ERC20、BTC）；签名回调确认 |

## 加密货币通道

不内嵌全节点：生成「收款地址 + 精确金额」二维码，由链上监听服务或人工调用签名回调确认。

```json
{
  "channels": {
    "crypto": {
      "enabled": true,
      "default_currency": "USDT_TRC20",
      "currencies": {
        "USDT_TRC20": { "address": "T...", "rate": 7.30, "confirmations": 1, "scheme": "tron", "decimals": 2 },
        "BTC":        { "address": "bc1...", "rate": 520000, "confirmations": 1, "scheme": "bitcoin", "decimals": 8 }
      },
      "notify_secret": "换成随机串"
    }
  }
}
```

- `rate` = 1 币值多少元；系统按订单法币金额换算币数量。
- 回调：`POST https://nownexts.com/payflow/notify/crypto`
  body：`{"order_no":"PF...","txid":"0x...","currency":"USDT_TRC20","amount":"6.85","sign":"..."}`
  `sign = hex(HMAC_SHA256(notify_secret, order_no|txid|currency|amount))`。验签通过即置为已支付并交付。
- 退款需人工（链上不可逆）。


## 配置位置

真实密钥**只写** `payflow/data/config.json`（gitignored，服务器为源），bootstrap 会与
`config/app.php` 深合并覆盖。示例：

```json
{
  "app": { "base_url": "https://nownexts.com/payflow" },
  "admin": { "token": "换成强随机令牌" },
  "channels": {
    "alipay": {
      "enabled": true,
      "app_id": "2021000000000000",
      "private_key": "-----BEGIN PRIVATE KEY-----\n...应用私钥...\n-----END PRIVATE KEY-----",
      "alipay_public_key": "-----BEGIN PUBLIC KEY-----\n...支付宝公钥...\n-----END PUBLIC KEY-----"
    },
    "wechat": {
      "enabled": true,
      "app_id": "wx0123456789abcdef",
      "mch_id": "1900000001",
      "serial_no": "商户API证书序列号",
      "api_v3_key": "32位APIv3密钥",
      "private_key": "-----BEGIN PRIVATE KEY-----\n...商户API私钥...\n-----END PRIVATE KEY-----",
      "platform_cert": "-----BEGIN CERTIFICATE-----\n...微信支付平台证书...\n-----END CERTIFICATE-----"
    }
  }
}
```

## 回调地址（部署后在商户后台填写）

| 通道 | 地址 |
|---|---|
| 支付宝 | `https://nownexts.com/payflow/notify/alipay`（表单 POST，成功返回纯文本 `success`） |
| 微信 | `https://nownexts.com/payflow/notify/wechat`（JSON POST，成功返回 `{"code":"SUCCESS"}`） |

## 验签与安全

- 支付宝：RSA2（SHA256withRSA），回调用支付宝公钥验签，校验 `trade_status` 与金额。
- 微信：APIv3，回调用平台证书验签 + AES-256-GCM 解密 `resource`，校验 `trade_state=SUCCESS` 与金额。
- 支付宝/微信的异步通知与主动查单都会走 `OrderService::markPaid()`，**幂等**：重复通知不会重复发货。
- 金额不符会拒绝入账（`支付金额不一致`）。

## 二维码

收银台默认用 `app.qr_endpoint` 前缀拼接支付串生成二维码。注重隐私可自建渲染端点，
或留空——留空时页面只展示可复制的支付串。

## 自检

```bash
php payflow/tests/run.php   # 含 RSA2 签名/验签、微信 Authorization/AES-GCM、状态机
```
