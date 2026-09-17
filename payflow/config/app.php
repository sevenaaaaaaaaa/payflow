<?php

declare(strict_types=1);

/**
 * PayFlow · 默认配置
 *
 * 敏感项（支付密钥、SMTP 密码、后台密码）不要写在这里——放到 data/config.json
 * （gitignored，服务器侧为准），bootstrap 会做深合并覆盖。
 */

return [
    'app' => [
        'name' => 'PayFlow',
        'env' => 'production',
        'debug' => false,
        // 部署入口：主域名子路径（nownexts.com/payflow）。若改用独立子域，
        // 把 base_url 设为 https://payflow.nownexts.com 且 base_path 设为空串。
        'base_url' => 'https://nownexts.com/payflow',
        'base_path' => '/payflow',
        'timezone' => 'Asia/Shanghai',
        // 二维码渲染端点（前缀拼接支付串）。默认用公共渲染服务，注重隐私可自建或留空
        // 留空时收银台只展示可复制的支付串。
        'qr_endpoint' => 'https://api.qrserver.com/v1/create-qr-code/?size=440x440&margin=8&data=',
    ],

    'data_dir' => PAYFLOW_ROOT . '/data',

    // 存储：MySQL 主库 → SQLite 辅助回退 → JSON 兜底
    // driver: auto（有 MySQL 凭据用 MySQL，否则 SQLite）| mysql | sqlite | json
    'database' => [
        'driver' => 'auto',
        'mysql' => [
            'enabled' => false,        // true 或环境变量 MYSQL_ENABLED=1
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'payflow',
            'username' => 'payflow',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
        // 辅助/回退库（文件位于 data/ 下，gitignored）
        'sqlite_path' => PAYFLOW_ROOT . '/data/payflow.sqlite',
    ],

    // 管理后台登录（用户名 + 密码；真实值放 data/config.json）
    'admin' => [
        'username' => 'admin',
        'password_hash' => '',   // 推荐：password_hash('你的密码', PASSWORD_DEFAULT) 的结果
        'password' => '',        // 备用：明文密码（本地开发方便）
        'session_key' => 'pf_admin',
    ],

    'catalog' => [
        'currency' => 'CNY',
        'default_currency_symbol' => '¥',
    ],

    // 支付通道。enabled=false 的通道不会出现在收银台。
    'channels' => [
        'manual' => [
            'enabled' => true,
            'label' => '人工到账（本地联调/测试）',
        ],
        'alipay' => [
            'enabled' => false,
            'label' => '支付宝当面付',
            'app_id' => '',
            'notify_url' => '',   // 留空则用 base_url/notify/alipay
            'gateway' => 'https://openapi.alipay.com/gateway.do',
            'private_key' => '',  // 应用私钥（PKCS8 PEM 或纯 base64）
            'alipay_public_key' => '', // 支付宝公钥（验签用）
        ],
        'wechat' => [
            'enabled' => false,
            'label' => '微信支付 Native',
            'mch_id' => '',
            'app_id' => '',
            'serial_no' => '',    // 商户 API 证书序列号
            'api_v3_key' => '',   // APIv3 密钥
            'private_key' => '',  // 商户 API 私钥（PEM）
            'platform_cert' => '', // 微信支付平台证书（验签用，PEM）
            'notify_url' => '',
        ],
        // 加密支付（USDT/BTC 等）：静态收款地址 + 金额二维码；由链上监听服务或人工回传签名回调确认
        'crypto' => [
            'enabled' => false,
            'label' => '加密货币',
            'default_currency' => 'USDT_TRC20',
            // 每种币：收款地址 + 法币汇率（1 币 = ? 元）+ 确认数
            'currencies' => [
                'USDT_TRC20' => ['address' => '', 'rate' => 7.30, 'confirmations' => 1, 'scheme' => 'tron', 'decimals' => 2],
                'USDT_ERC20' => ['address' => '', 'rate' => 7.30, 'confirmations' => 12, 'scheme' => 'ethereum', 'decimals' => 2],
                'BTC' => ['address' => '', 'rate' => 520000, 'confirmations' => 1, 'scheme' => 'bitcoin', 'decimals' => 8],
            ],
            // 回传回调签名密钥（HMAC）；留空则用全站 secret
            'notify_secret' => '',
        ],
    ],

    // 临时支付链接
    'payment_link' => [
        'default_expiry_days' => 7,
        'max_uses' => 0,   // 0 = 不限
    ],

    // 订阅计费
    'subscription' => [
        'retry_offsets_days' => [1, 3, 5],  // 扣款失败重试节奏（第 n 天后重试）
        'grace_days' => 3,                   // 宽限期：到期后保留权益的天数，之后降级
        'remind_before_days' => 3,           // 到期前提醒天数
        'auto_renew' => true,                // 是否有自动续费引擎（cron）
    ],

    // 优惠券
    'coupon' => [
        'max_per_order' => 1,
    ],

    // 推荐裂变 & 佣金
    'referral' => [
        'cookie_days' => 30,                 // 点击归因留存天数
        'default_commission_rate' => 0.20,   // 默认佣金比例
        'hold_days' => 7,                    // 佣金冻结天数（防退款），之后可提现
        'levels' => [                        // 大使层级 → 佣金比例
            'standard' => 0.20,
            'ambassador' => 0.30,
            'partner' => 0.40,
        ],
        'cookie_name' => 'pf_ref',
    ],

    // 提现
    'payout' => [
        'min_amount_cents' => 10000,         // 最低提现额
        'methods' => ['alipay', 'wechat', 'bank', 'manual'],
    ],

    // 数字商品存储（文件/License）
    'storage' => [
        'uploads_dir' => PAYFLOW_ROOT . '/uploads',
        'download_ttl' => 3600,              // 签名下载链接有效期（秒）
        'default_download_limit' => 0,       // 0 = 不限次
    ],

    // 开放能力
    'api' => [
        'enabled' => true,
        'key_prefix' => 'pfk_',
    ],

    // 发票 / 收据
    'invoice' => [
        'enabled' => true,
        'prefix' => 'INV',
        'seller_name' => 'PayFlow',
        'seller_tax_id' => '',
        'seller_address' => '',
    ],

    // 全站签名密钥（签名下载/API HMAC/结账令牌）。留空则回退到后台密码哈希。
    'secret' => '',

    // 邮件通知（SMTP 直发，无第三方依赖）
    'mail' => [
        'enabled' => false,
        'transport' => 'smtp',      // smtp | mail
        'host' => 'smtp.example.com',
        'port' => 465,
        'secure' => 'ssl',          // ssl | tls | none
        'username' => '',
        'password' => '',
        'from_email' => 'no-reply@nownexts.com',
        'from_name' => 'PayFlow',
        'notify_admin' => '',       // 收款/退款抄送管理员，可留空
    ],

    // 出站 Webhook（订单/订阅/佣金事件推送给 UserLoop / 任意 MA）
    'webhooks' => [
        'order' => [
            'enabled' => false,
            'url' => '',
            'secret' => '',
        ],
        'max_attempts' => 5,
    ],
];
