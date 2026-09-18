<?php

declare(strict_types=1);

/**
 * PayFlow 自检：无需框架、无外部依赖。
 *
 *   php payflow/tests/run.php
 */

$config = require dirname(__DIR__) . '/bootstrap.php';

use PayFlow\Domain\OrderStateMachine;
use PayFlow\Payment\Signer\AlipaySigner;
use PayFlow\Payment\Signer\WechatSigner;
use PayFlow\Store\JsonStore;
use PayFlow\Support\Money;

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✓ {$name}\n";
    } else {
        $failed++;
        echo "  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        if (is_file($dir)) {
            @unlink($dir);
        }
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            rrmdir($dir . '/' . $entry);
        }
    }
    @rmdir($dir);
}

echo "PayFlow 自检\n";

echo "\n[状态机]\n";
check('created → paid 允许', OrderStateMachine::can('created', 'paid'));
check('created → delivered 禁止', !OrderStateMachine::can('created', 'delivered'));
check('paid → delivered 允许', OrderStateMachine::can('paid', 'delivered'));
check('delivered → refunded 允许', OrderStateMachine::can('delivered', 'refunded'));
check('refunded 为终态', OrderStateMachine::isTerminal('refunded'));
check('paid 属于已支付族', OrderStateMachine::isPaidLike('paid'));
try {
    OrderStateMachine::assert('created', 'refunded');
    check('非法流转抛异常', false);
} catch (\RuntimeException) {
    check('非法流转抛异常', true);
}

echo "\n[金额]\n";
check('19.9 元 = 1990 分', Money::toCents('19.9') === 1990);
check('19.99 元 = 1999 分', Money::toCents('19.99') === 1999);
check('展示格式 ¥1,999.00', Money::yuan(199900) === '¥1,999.00');

echo "\n[支付宝签名]\n";
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $privatePem);
$publicPem = openssl_pkey_get_details($key)['key'];
$params = ['app_id' => '2021000000000000', 'method' => 'alipay.trade.query', 'charset' => 'utf-8', 'biz_content' => '{"out_trade_no":"PF1"}', 'sign_type' => 'RSA2'];
$params['sign'] = AlipaySigner::sign($params, $privatePem);
check('RSA2 签名可被公钥验证', AlipaySigner::verify($params, $publicPem));
$tampered = $params;
$tampered['biz_content'] = '{"out_trade_no":"PF2"}';
check('篡改后验签失败', !AlipaySigner::verify($tampered, $publicPem));
check('兼容纯 base64 公钥', AlipaySigner::verify($params, preg_replace('/-----[^-]+-----|\s/', '', $publicPem) ?? ''));

echo "\n[微信签名与解密]\n";
$auth = WechatSigner::authorization('POST', '/v3/pay/transactions/native', '{"a":1}', '1900000001', 'SERIAL123', $privatePem);
check('Authorization 头结构正确', str_starts_with($auth, 'WECHATPAY2-SHA256-RSA2048 mchid="1900000001"') && str_contains($auth, 'serial_no="SERIAL123"'));
$apiKey = str_repeat('a', 32);
$nonce = 'abcdef123456';
$ad = 'transaction';
$plain = '{"out_trade_no":"PF9","trade_state":"SUCCESS"}';
$tag = '';
$cipher = openssl_encrypt($plain, 'aes-256-gcm', $apiKey, OPENSSL_RAW_DATA, $nonce, $tag, $ad);
$decrypted = WechatSigner::decryptResource(base64_encode($cipher . $tag), $nonce, $ad, $apiKey);
check('AES-256-GCM 解密回调', $decrypted === $plain, $decrypted);
$notifySig = WechatSigner::sign("123\n{$nonce}\n{\"x\":1}\n", $privatePem);
check('回调验签通过', WechatSigner::verifyNotify('123', $nonce, '{"x":1}', $notifySig, $publicPem));

echo "\n[JSON 存储]\n";
$tmp = sys_get_temp_dir() . '/payflow-test-' . bin2hex(random_bytes(4));
$store = new JsonStore($tmp, 'demo');
$store->put(['id' => 'a1', 'v' => 1]);
$store->put(['id' => 'a2', 'v' => 2]);
check('写入并读取', $store->find('a1')['v'] === 1 && count($store->all()) === 2);
$store->mutate(static function (array $records): array {
    $records['a1']['v'] = 99;

    return $records;
});
check('读改写事务', (new JsonStore($tmp, 'demo'))->find('a1')['v'] === 99);
$store->delete('a2');
check('删除记录', !(new JsonStore($tmp, 'demo'))->has('a2'));
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

echo "\n[存储驱动]\n";
$tmpDb = sys_get_temp_dir() . '/pf-db-' . bin2hex(random_bytes(4));
@mkdir($tmpDb, 0775, true);
$dbFactory = new \PayFlow\Store\StoreFactory(['database' => ['driver' => 'sqlite', 'sqlite_path' => $tmpDb . '/t.sqlite']], $tmpDb);
check('SQLite 驱动可用', $dbFactory->driver() === 'sqlite', $dbFactory->driver() . ' / ' . (string) $dbFactory->error());
$sqlStore = $dbFactory->store('demo');
$sqlStore->put(['id' => 's1', 'v' => 1]);
$sqlStore->put(['id' => 's2', 'v' => 2]);
check('SqlStore 写入读取', $sqlStore->find('s1')['v'] === 1 && count($sqlStore->all()) === 2);
$sqlStore->mutate(static function (array $records): array {
    $records['s1']['v'] = 42;
    return $records;
});
check('SqlStore 事务读改写', $dbFactory->store('demo')->find('s1')['v'] === 42);
$sqlStore->delete('s2');
check('SqlStore 删除', !$dbFactory->store('demo')->has('s2'));

$qt = $dbFactory->store('qt');
$qt->put(['id' => 'q1', 'kind' => 'a', 'created_at' => '2024-01-01T00:00:00+08:00']);
$qt->put(['id' => 'q2', 'kind' => 'a', 'created_at' => '2024-01-03T00:00:00+08:00']);
$qt->put(['id' => 'q3', 'kind' => 'b', 'created_at' => '2024-01-02T00:00:00+08:00']);
check('query 等值过滤+排序', array_map(static fn (array $r): string => $r['id'], $qt->query(['kind' => 'a'], 0, 0, 'created_at', 'desc')) === ['q2', 'q1']);
check('query 分页 offset/limit', array_map(static fn (array $r): string => $r['id'], $qt->query([], 2, 1, 'created_at', 'asc')) === ['q3', 'q2']);
check('count 过滤计数', $qt->count(['kind' => 'a']) === 2);
check('aggregate 条件计数', ($qt->aggregate([['field' => 'kind', 'op' => '=', 'value' => 'a']])[0]['count'] ?? 0) === 2);
check('aggregate 分组求和', (function () use ($qt): bool {
    foreach ($qt->aggregate([], 'kind', null) as $row) {
        if ($row['key'] === 'a' && $row['count'] === 2) {
            return true;
        }
    }
    return false;
})());
check('aggregate in/范围条件', ($qt->aggregate([['field' => 'created_at', 'op' => '>=', 'value' => '2024-01-02']])[0]['count'] ?? 0) === 2);
check('groupByDay 按天分组', ($qt->groupByDay('created_at')[0]['key'] ?? '') === '2024-01-01');
check('search 多字段检索', count($qt->search(['kind'], 'a')) === 2 && $qt->searchCount(['kind'], 'a') === 2);
check('search 空词返回全部', $qt->searchCount(['kind'], '') === 3);
rrmdir($tmpDb);

echo "\n[订阅续费引擎]\n";
$tmpSub = sys_get_temp_dir() . '/pf-sub-' . bin2hex(random_bytes(4));
$subCfg = [
    'app' => ['base_url' => 'http://localhost', 'base_path' => '', 'timezone' => 'Asia/Shanghai'],
    'data_dir' => $tmpSub,
    'admin' => ['username' => 'admin', 'password' => 'x'],
    'channels' => [
        'manual' => ['enabled' => true, 'label' => 'manual'],
        'alipay' => ['enabled' => false],
        'wechat' => ['enabled' => false],
    ],
    'subscription' => ['retry_offsets_days' => [1, 3, 5], 'grace_days' => 3, 'remind_before_days' => 3],
    'mail' => ['enabled' => false],
    'webhooks' => ['order' => ['enabled' => false]],
    'secret' => 'test-secret',
];
$subApp = new \PayFlow\Application($subCfg);
$subApp->products->create([
    'name' => '月订阅', 'slug' => 'sub-month', 'type' => 'subscription', 'amount_cents' => 3900,
    'interval' => 'month', 'entitlement' => ['kind' => 'membership', 'membership_level' => 'creator'],
]);
$pid = (string) $subApp->products->findBySlug('sub-month')['id'];
$first = $subApp->orderService->startCheckout($pid, 'sub@test.com', '小测', 'manual');
$paid = $subApp->orderService->markPaid((string) $first['order']['id'], 'T1', (int) $first['order']['amount_cents'], []);
check('订阅首单支付后交付', ($paid['status'] ?? '') === 'delivered');
$sub = $subApp->subscriptions->findByCustomerAndProduct((string) $paid['customer_id'], $pid);
check('订阅已创建且 active', ($sub['status'] ?? '') === 'active');
check('周期约 +1 月', strtotime((string) $sub['current_period_end']) > time() + 25 * 86400);

$subApp->subscriptions->update((string) $sub['id'], ['current_period_end' => date('c', time() - 3600)]);
$rep = $subApp->subscriptionService->renewDue();
check('到期触发续费流程', $rep['due'] === 1 && in_array($sub['id'], $rep['failed'], true), json_encode($rep));
$sub2 = $subApp->subscriptions->find((string) $sub['id']);
check('转 past_due 且生成续费订单', ($sub2['status'] ?? '') === 'past_due' && !empty($sub2['renewal_order_id']));

$rep2 = $subApp->subscriptionService->renewDue();
check('未到重试时间不重复扣', ($rep2['failed'] ?? []) === [], json_encode($rep2));

$renewOrder = $subApp->orders->find((string) $sub2['renewal_order_id']);
$beforeEnd = strtotime((string) $sub2['current_period_end']);
$subApp->orderService->markPaid((string) $renewOrder['id'], 'T2', (int) $renewOrder['amount_cents'], []);
$sub3 = $subApp->subscriptions->find((string) $sub['id']);
check('续费支付后恢复 active', ($sub3['status'] ?? '') === 'active', json_encode(['status' => $sub3['status'] ?? null]));
check('续费支付后周期顺延', strtotime((string) $sub3['current_period_end']) > $beforeEnd);
check('续费后重试计数清零', (int) ($sub3['failed_attempts'] ?? 1) === 0);

$subApp->subscriptions->update((string) $sub['id'], ['current_period_end' => date('c', time() - 10 * 86400), 'next_retry_at' => null]);
$rep3 = $subApp->subscriptionService->renewDue();
check('宽限期后降级', in_array($sub['id'], $rep3['downgraded'], true), json_encode($rep3));
$sub4 = $subApp->subscriptions->find((string) $sub['id']);
check('降级后订阅取消', ($sub4['status'] ?? '') === 'canceled');
$activeEnt = array_filter($subApp->entitlements->forSubscription((string) $sub['id']), static fn (array $e): bool => ($e['status'] ?? '') === 'active');
check('降级后权益撤销', $activeEnt === []);
$customer = $subApp->customers->find((string) $paid['customer_id']);
check('降级后会员等级清空', ($customer['membership_level'] ?? null) === null);
rrmdir($tmpSub);

echo "\n[优惠券]\n";
$tmpCp = sys_get_temp_dir() . '/pf-cp-' . bin2hex(random_bytes(4));
$cpCfg = $subCfg;
$cpCfg['data_dir'] = $tmpCp;
$cpApp = new \PayFlow\Application($cpCfg);
$cpApp->products->create(['name' => '课程', 'slug' => 'course', 'type' => 'one_time', 'amount_cents' => 10000, 'entitlement' => ['kind' => 'content', 'items' => [['title' => 'L1', 'url' => 'https://x/1']]]]);
$cpPid = (string) $cpApp->products->findBySlug('course')['id'];
$cpApp->coupons->create(['code' => 'save20', 'type' => 'percent', 'value' => 20, 'max_redemptions' => 1, 'per_customer_limit' => 1, 'active' => true]);

$valid = $cpApp->couponService->validate('SAVE20', $cpApp->products->find($cpPid), 10000, 'a@test.com');
check('优惠券大小写不敏感且 20% 折扣', ($valid['ok'] ?? false) && $valid['discount_cents'] === 2000, json_encode($valid));

$res = $cpApp->orderService->startCheckout($cpPid, 'a@test.com', 'A', 'manual', ['coupon' => 'SAVE20']);
check('下单应用优惠券后金额正确', (int) $res['order']['amount_cents'] === 8000 && (int) $res['order']['discount_cents'] === 2000);
$cpApp->orderService->markPaid((string) $res['order']['id'], 'C1', 8000, []);
$cpAfter = $cpApp->coupons->findByCode('SAVE20');
check('支付后核销记录 used 且计数 +1', (int) $cpAfter['redeemed_count'] === 1 && ($cpApp->redemptions->forOrder((string) $res['order']['id'])['status'] ?? '') === 'used');

$second = $cpApp->couponService->validate('SAVE20', $cpApp->products->find($cpPid), 10000, 'b@test.com');
check('超出总次数上限被拒绝', ($second['ok'] ?? true) === false, json_encode($second));

$cpApp->coupons->create(['code' => 'REL10', 'type' => 'fixed', 'value' => 1000, 'active' => true]);
$res2 = $cpApp->orderService->startCheckout($cpPid, 'c@test.com', 'C', 'manual', ['coupon' => 'REL10']);
check('满减券金额正确', (int) $res2['order']['amount_cents'] === 9000);
$cpApp->orderService->cancel((string) $res2['order']['id'], 'test');
$cpRel = $cpApp->coupons->findByCode('REL10');
check('取消订单释放优惠券', (int) $cpRel['redeemed_count'] === 0 && ($cpApp->redemptions->forOrder((string) $res2['order']['id'])['status'] ?? '') === 'released');
rrmdir($tmpCp);

echo "\n[推荐 · 佣金 · 提现]\n";
$tmpRef = sys_get_temp_dir() . '/pf-ref-' . bin2hex(random_bytes(4));
$refCfg = $cpCfg;
$refCfg['data_dir'] = $tmpRef;
$refCfg['referral'] = ['cookie_days' => 30, 'default_commission_rate' => 0.2, 'hold_days' => 7, 'levels' => ['standard' => 0.2, 'ambassador' => 0.3], 'cookie_name' => 'pf_ref'];
$refCfg['payout'] = ['min_amount_cents' => 1000, 'methods' => ['manual']];
$refApp = new \PayFlow\Application($refCfg);
$refApp->products->create(['name' => '商品', 'slug' => 'g1', 'type' => 'one_time', 'amount_cents' => 10000, 'entitlement' => ['kind' => 'content', 'items' => [['title' => 'x', 'url' => 'https://x/1']]]]);
$gid = (string) $refApp->products->findBySlug('g1')['id'];
$referrer = $refApp->referralService->findOrCreate('referrer@test.com', '推荐人');
check('推荐人可创建且生成推荐码', ($referrer['code'] ?? '') !== '');

$refApp->referralService->trackClick((string) $referrer['code']);
check('点击计数 +1', (int) $refApp->referrals->find((string) $referrer['id'])['clicks'] === 1);

$o1 = $refApp->orderService->startCheckout($gid, 'buyer@test.com', '买家', 'manual', ['referral' => (string) $referrer['code']]);
check('订单归因到推荐人', ($o1['order']['referral_id'] ?? '') === $referrer['id']);
$refApp->orderService->markPaid((string) $o1['order']['id'], 'R1', 10000, []);
$com = $refApp->commissions->forOrder((string) $o1['order']['id']);
check('支付后生成 20% 佣金', ($com['amount_cents'] ?? 0) === 2000 && ($com['status'] ?? '') === 'pending');
check('推荐人成交数 +1', (int) $refApp->referrals->find((string) $referrer['id'])['orders'] === 1);

check('冻结期内不可提现', $refApp->referralService->withdrawable((string) $referrer['id']) === 0);
check('未到期佣金不解冻', $refApp->commissionService->mature() === []);
$refApp->commissions->update((string) $com['id'], ['available_at' => date('c', time() - 60)]);
check('到期后解冻', in_array($com['id'], $refApp->commissionService->mature(), true));
check('解冻后可提现 2000', $refApp->referralService->withdrawable((string) $referrer['id']) === 2000);

$payout = $refApp->referralService->requestPayout($referrer, 2000, 'manual', 'alipay:13800000000');
check('提现申请创建', ($payout['status'] ?? '') === 'requested');
check('申请后余额被冻结', $refApp->referralService->withdrawable((string) $referrer['id']) === 0);
$refApp->referralService->approvePayout((string) $payout['id']);
$refApp->referralService->payPayout((string) $payout['id'], 'TRX123');
check('打款后佣金标记 paid', ($refApp->commissions->find((string) $com['id'])['status'] ?? '') === 'paid');
check('打款单号记录', ($refApp->payouts->find((string) $payout['id'])['transfer_no'] ?? '') === 'TRX123');

$o2 = $refApp->orderService->startCheckout($gid, 'buyer2@test.com', '买家2', 'manual', ['referral' => (string) $referrer['code']]);
$refApp->orderService->markPaid((string) $o2['order']['id'], 'R2', 10000, []);
$refApp->orderService->refund((string) $o2['order']['id'], 10000, 'test');
check('退款冲正未打款佣金', ($refApp->commissions->forOrder((string) $o2['order']['id'])['status'] ?? '') === 'reversed');

$self = $refApp->orderService->startCheckout($gid, 'referrer@test.com', '自己', 'manual', ['referral' => (string) $referrer['code']]);
$refApp->orderService->markPaid((string) $self['order']['id'], 'R3', 10000, []);
check('自荐不计佣', $refApp->commissions->forOrder((string) $self['order']['id']) === null);
rrmdir($tmpRef);

echo "\n[数字交付 · License]\n";
$tmpDl = sys_get_temp_dir() . '/pf-dl-' . bin2hex(random_bytes(4));
$dlCfg = $cpCfg;
$dlCfg['data_dir'] = $tmpDl;
$dlCfg['storage'] = ['uploads_dir' => $tmpDl . '/uploads', 'download_ttl' => 3600, 'max_bytes' => 1048576];
$dlApp = new \PayFlow\Application($dlCfg);
$dlApp->products->create(['name' => '源码包', 'slug' => 'src', 'type' => 'one_time', 'amount_cents' => 5000, 'license_enabled' => true, 'license_prefix' => 'SRC', 'entitlement' => ['kind' => 'content', 'items' => []]]);
$dlp = $dlApp->products->findBySlug('src');
$dlpid = (string) $dlp['id'];

$tmpFile = tempnam(sys_get_temp_dir(), 'pfup');
file_put_contents($tmpFile, 'hello-delivery-content');
$asset = $dlApp->deliveryService->storeUpload($dlpid, ['name' => 'src.zip', 'tmp_name' => $tmpFile, 'size' => filesize($tmpFile), 'error' => UPLOAD_ERR_OK], '完整源码', 1);
check('文件上传入库（含 sha256）', ($asset['sha256'] ?? '') !== '' && is_file((string) $asset['path']));

$o = $dlApp->orderService->startCheckout($dlpid, 'dl@test.com', 'D', 'manual');
$paidOrder = $dlApp->orderService->markPaid((string) $o['order']['id'], 'D1', 5000, []);
$lic = $dlApp->deliveryService->licenseForOrder($paidOrder);
check('交付时自动签发 License', ($lic['license_key'] ?? '') !== '' && str_starts_with((string) $lic['license_key'], 'SRC-'));
check('订单记录 license_id', ($paidOrder['license_id'] ?? '') === ($lic['id'] ?? ''));

$url = $dlApp->deliveryService->signedDownloadUrl($paidOrder, $asset);
$token = substr($url, strrpos($url, '/') + 1);
$resolved = $dlApp->deliveryService->resolveDownload($token);
check('签名链接可解析', ($resolved['asset']['id'] ?? '') === $asset['id']);
$dlApp->deliveryService->assertDownloadable($asset, $paidOrder);
$dlApp->deliveryService->recordDownload($asset, $paidOrder);
try {
    $dlApp->deliveryService->assertDownloadable($asset, $paidOrder);
    check('超过下载次数被拒', false);
} catch (\RuntimeException) {
    check('超过下载次数被拒', true);
}

$tampered = substr($token, 0, -3) . 'xyz';
try {
    $dlApp->deliveryService->resolveDownload($tampered);
    check('篡改签名被拒', false);
} catch (\RuntimeException) {
    check('篡改签名被拒', true);
}

$refunded = $dlApp->orderService->refund((string) $o['order']['id'], 5000, 't');
try {
    $dlApp->deliveryService->assertDownloadable($asset, $refunded);
    check('退款后禁止下载', false);
} catch (\RuntimeException) {
    check('退款后禁止下载', true);
}
rrmdir($tmpDl);

echo "\n[开放能力 · 看板]\n";
$tmpOp = sys_get_temp_dir() . '/pf-op-' . bin2hex(random_bytes(4));
$opCfg = $cpCfg;
$opCfg['data_dir'] = $tmpOp;
$opCfg['invoice'] = ['enabled' => true, 'prefix' => 'INV', 'seller_name' => 'PayFlow'];
$opCfg['api'] = ['enabled' => true, 'key_prefix' => 'pfk_'];
$opCfg['webhooks'] = ['order' => ['enabled' => true, 'url' => 'http://127.0.0.1:9/nope', 'secret' => 's'], 'max_attempts' => 1];
$opApp = new \PayFlow\Application($opCfg);
$opApp->products->create(['name' => '开放品', 'slug' => 'op', 'type' => 'one_time', 'amount_cents' => 12300, 'entitlement' => ['kind' => 'content', 'items' => []]]);
$opid = (string) $opApp->products->findBySlug('op')['id'];
$oo = $opApp->orderService->startCheckout($opid, 'op@test.com', 'O', 'manual');
$paidOp = $opApp->orderService->markPaid((string) $oo['order']['id'], 'OP1', 12300, []);

$inv = $opApp->invoices->forOrder((string) $oo['order']['id']);
check('交付时自动开票', ($inv['number'] ?? '') !== '' && str_starts_with((string) $inv['number'], 'INV-'));
check('订单记录 invoice_id', ($paidOp['invoice_id'] ?? '') === ($inv['id'] ?? ''));
$invUrl = $opApp->invoiceService->urlFor($inv);
$invToken = substr($invUrl, strrpos($invUrl, '/') + 1);
check('发票签名链接可解析', ($opApp->invoiceService->resolveToken($invToken)['id'] ?? '') === $inv['id']);

$summary = $opApp->analyticsService->summary(30);
check('看板 GMV 正确', (int) $summary['gmv_cents'] === 12300 && (int) $summary['paid'] === 1);
check('看板渠道分布', isset($summary['channels']['manual']) && (int) $summary['channels']['manual']['gmv'] === 12300);
check('看板转化率 100%', (float) $summary['conversion_rate'] === 100.0);

$opApp->webhooks->dispatch('order.paid', ['order_no' => 'OP1']);
$deliveries = array_values($opApp->webhookDeliveries->all());
check('Webhook 投递留痕', count($deliveries) >= 1 && (int) end($deliveries)['attempts'] >= 1);
check('Webhook 失败标记', in_array(end($deliveries)['status'], ['pending', 'failed'], true));

$keyId = 'pfk_testkey';
$secret = 'testsecret123';
$opApp->apiKeys->insert(['name' => 't', 'key_id' => $keyId, 'secret_hash' => password_hash($secret, PASSWORD_DEFAULT), 'secret_signing' => $secret, 'active' => true]);
$bearerReq = new \PayFlow\Http\Request('GET', '/api/v1/products', [], [], ['Authorization' => 'Bearer ' . $keyId . '.' . $secret], '');
check('API Bearer 鉴权通过', isset($opApp->apiAuth->authenticate($bearerReq)['key']));
$badReq = new \PayFlow\Http\Request('GET', '/api/v1/products', [], [], ['Authorization' => 'Bearer ' . $keyId . '.wrong'], '');
check('API Bearer 错误密钥被拒', isset($opApp->apiAuth->authenticate($badReq)['error']));
$ts = (string) time();
$msg = $ts . "\nGET\n/api/v1/products\n";
$hmacReq = new \PayFlow\Http\Request('GET', '/api/v1/products', [], [], ['X-PF-Key' => $keyId, 'X-PF-Timestamp' => $ts, 'X-PF-Signature' => hash_hmac('sha256', $msg, $secret)], '');
check('API HMAC 鉴权通过', isset($opApp->apiAuth->authenticate($hmacReq)['key']));
$metaReq = new \PayFlow\Http\Request('GET', '/api/v1/meta', [], [], ['Authorization' => 'Bearer ' . $keyId . '.' . $secret], '');
$metaResp = (new \PayFlow\Http\Controller\ApiController($opApp))->meta($metaReq);
$metaBody = json_decode($metaResp->body, true);
check('能力清单 /api/v1/meta 可用', ($metaBody['product'] ?? '') === 'PayFlow' && count($metaBody['events'] ?? []) === 12 && ($metaBody['endpoints'] ?? []) !== []);
rrmdir($tmpOp);

echo "\n[互通 · 事件信封 / Outbox / 入站幂等]\n";
$tmpI = sys_get_temp_dir() . '/pf-ie-' . bin2hex(random_bytes(4));
$iCfg = $cpCfg;
$iCfg['data_dir'] = $tmpI;
$iApp = new \PayFlow\Application($iCfg);
$iApp->products->create(['name' => '互通品', 'slug' => 'ie', 'type' => 'one_time', 'amount_cents' => 1000, 'entitlement' => ['kind' => 'content', 'items' => []]]);
$iid = (string) $iApp->products->findBySlug('ie')['id'];
$io = $iApp->orderService->startCheckout($iid, 'io@test.com', 'IO', 'manual', ['external_id' => 'ext-1', 'tenant' => 't1']);
check('订单记录 external_id/tenant', ($io['order']['external_id'] ?? '') === 'ext-1' && ($io['order']['tenant'] ?? '') === 't1');
$iop = $iApp->orderService->markPaid((string) $io['order']['id'], 'IO1', 1000, []);
$outbox = $iApp->outbox->since('', 50);
$hasEnvelope = false;
foreach ($outbox as $e) {
    if (!empty($e['idempotency_key']) && !empty($e['subject']) && ($e['type'] ?? '') !== '') {
        $hasEnvelope = true;
        break;
    }
}
check('Outbox 记录统一信封', $hasEnvelope && count($outbox) >= 1);
check('增量拉取可用', $iApp->outbox->since('', 1) !== []);
$rev = $iApp->inboundEventService->handle(['type' => 'entitlement.revoke', 'source' => 'learnflow', 'data' => ['order_no' => $iop['order_no']], 'idempotency_key' => 'k-1']);
check('入站事件撤销权益', ($rev['applied'] ?? '') === 'revoked');
$rev2 = $iApp->inboundEventService->handle(['type' => 'entitlement.revoke', 'data' => ['order_no' => $iop['order_no']], 'idempotency_key' => 'k-1']);
check('入站事件幂等去重', ($rev2['duplicate'] ?? false) === true);
$cust = $iApp->customers->findByEmail('io@test.com');
check('客户 external_id 落库', ($cust['external_id'] ?? '') === 'ext-1');
rrmdir($tmpI);

echo "\n[临时支付链接 · 加密 · 发卡 · 兑换券]\n";
$tmpX = sys_get_temp_dir() . '/pf-x-' . bin2hex(random_bytes(4));
$xCfg = $cpCfg;
$xCfg['data_dir'] = $tmpX;
$xCfg['channels'] = [
    'manual' => ['enabled' => true, 'label' => 'manual'],
    'alipay' => ['enabled' => false],
    'wechat' => ['enabled' => false],
    'crypto' => [
        'enabled' => true, 'label' => 'crypto', 'default_currency' => 'USDT_TRC20',
        'currencies' => ['USDT_TRC20' => ['address' => 'TXyz1234567890', 'rate' => 7.30, 'confirmations' => 1, 'scheme' => 'tron', 'decimals' => 2]],
        'notify_secret' => 'cryptosecret',
    ],
];
$xApp = new \PayFlow\Application($xCfg);
$xApp->products->create(['name' => '发卡商品', 'slug' => 'cardp', 'type' => 'one_time', 'amount_cents' => 5000, 'card_enabled' => true, 'entitlement' => ['kind' => 'content', 'items' => []]]);
$xApp->products->create(['name' => '赠品', 'slug' => 'giftp', 'type' => 'one_time', 'amount_cents' => 9900, 'entitlement' => ['kind' => 'content', 'items' => [['title' => 'G', 'url' => 'https://x/g']]]]);
$cardPid = (string) $xApp->products->findBySlug('cardp')['id'];
$giftPid = (string) $xApp->products->findBySlug('giftp')['id'];

// 发卡
$xApp->cards->insert(['product_id' => $cardPid, 'code' => 'CARD-TEST-0001', 'status' => 'available', 'batch' => 't']);
$co = $xApp->orderService->startCheckout($cardPid, 'card@test.com', 'C', 'manual');
$cardOrder = $xApp->orderService->markPaid((string) $co['order']['id'], 'C1', 5000, []);
check('购买自动发卡', ($cardOrder['cards'][0] ?? '') === 'CARD-TEST-0001');
check('卡密标记已发出', ($xApp->cards->forProduct($cardPid)[0]['status'] ?? '') === 'issued');
$co2 = $xApp->orderService->startCheckout($cardPid, 'card2@test.com', 'C2', 'manual');
$cardOrder2 = $xApp->orderService->markPaid((string) $co2['order']['id'], 'C2', 5000, []);
check('库存不足时不发卡且不报错', ($cardOrder2['cards'] ?? []) === []);

// 临时支付链接
$link = $xApp->paymentLinks->create(['title' => '咨询费', 'amount_cents' => 19900, 'expires_at' => date('c', time() + 3600)]);
check('支付链接可创建', ($link['token'] ?? '') !== '' && $xApp->paymentLinkService->resolve((string) $link['token']) !== null);
$lo = $xApp->orderService->startAdHocCheckout($link, 'link@test.com', 'L', 'manual');
check('支付链接下单金额正确', (int) $lo['order']['amount_cents'] === 19900 && ($lo['order']['type'] ?? '') === 'link');
$xApp->paymentLinkService->consume($link);
check('链接用量 +1', (int) $xApp->paymentLinks->find((string) $link['id'])['used'] === 1);

// 加密支付
$cryptoOrder = $xApp->orderService->startCheckout($cardPid, 'crypto@test.com', 'C', 'crypto');
$intent = $cryptoOrder['intent'];
check('加密通道生成地址与金额', str_starts_with((string) $intent['content'], 'tron:') && ($cryptoOrder['order']['channel_payload']['amount_crypto'] ?? 0) > 0, json_encode($intent));
$orderNo = (string) $cryptoOrder['order']['order_no'];
$body = ['order_no' => $orderNo, 'txid' => '0xdeadbeef', 'currency' => 'USDT_TRC20', 'amount' => '6.85'];
$sig = hash_hmac('sha256', implode('|', [$orderNo, '0xdeadbeef', 'USDT_TRC20', '6.85']), 'cryptosecret');
$req = new \PayFlow\Http\Request('POST', '/notify/crypto', [], [], ['Content-Type' => 'application/json'], (string) json_encode($body + ['sign' => $sig]));
$notify = $xApp->channels->get('crypto')->parseNotify($req);
check('加密回调验签通过并判为已支付', ($notify->paid ?? false) === true && $notify->channelTradeNo === '0xdeadbeef');
$badReq = new \PayFlow\Http\Request('POST', '/notify/crypto', [], [], ['Content-Type' => 'application/json'], (string) json_encode($body + ['sign' => 'bad']));
try {
    $xApp->channels->get('crypto')->parseNotify($badReq);
    check('加密回调错误签名被拒', false);
} catch (\RuntimeException) {
    check('加密回调错误签名被拒', true);
}

// 兑换券
$voucher = $xApp->vouchers->insert(['product_id' => $giftPid, 'code' => 'GIFT-TEST', 'kind' => 'free', 'max_uses' => 1, 'used_count' => 0, 'active' => true]);
$voucherOrder = $xApp->voucherService->redeem('GIFT-TEST', 'gift@test.com', 'G');
check('兑换券生成已交付订单', ($voucherOrder['status'] ?? '') === 'delivered' && (int) $voucherOrder['amount_cents'] === 0);
check('兑换券已核销', (int) $xApp->vouchers->find((string) $voucher['id'])['used_count'] === 1);
try {
    $xApp->voucherService->redeem('GIFT-TEST', 'gift2@test.com', 'G2');
    check('兑换券不可重复使用', false);
} catch (\RuntimeException) {
    check('兑换券不可重复使用', true);
}
rrmdir($tmpX);

echo "\n[工程维护]\n";
$tmpMt = sys_get_temp_dir() . '/pf-mt-' . bin2hex(random_bytes(4));
$mtCfg = $cpCfg;
$mtCfg['data_dir'] = $tmpMt;
$mtCfg['maintenance'] = ['events_retention_days' => 30, 'events_max_rows' => 3];
$mtApp = new \PayFlow\Application($mtCfg);
$mtApp->products->create(['name' => '维护品', 'slug' => 'mt', 'type' => 'one_time', 'amount_cents' => 100, 'entitlement' => ['kind' => 'content', 'items' => []]]);
$mtpid = (string) $mtApp->products->findBySlug('mt')['id'];
check('firstBy 按字段命中', $mtApp->products->firstBy('slug', 'mt')['id'] === $mtpid);
check('firstBy 未命中返回 null', $mtApp->products->firstBy('slug', 'nope') === null);

$o = $mtApp->orderService->startCheckout($mtpid, 'mt@test.com', 'M', 'manual');
$failedOrder = $mtApp->orderService->fail((string) $o['order']['id'], 'test');
check('订单可标记失败', ($failedOrder['status'] ?? '') === 'failed');

$lic = null;
$mtApp->products->create(['name' => '证书品', 'slug' => 'lic', 'type' => 'one_time', 'amount_cents' => 5000, 'license_enabled' => true, 'license_prefix' => 'LV', 'entitlement' => ['kind' => 'content', 'items' => []]]);
$lid = (string) $mtApp->products->findBySlug('lic')['id'];
$lo = $mtApp->orderService->startCheckout($lid, 'lic@test.com', 'L', 'manual');
$lp = $mtApp->orderService->markPaid((string) $lo['order']['id'], 'LV1', 5000, []);
$lic = $mtApp->deliveryService->licenseForOrder($lp);
check('License 可按密钥反查', ($mtApp->licenses->findByKey((string) $lic['license_key'])['id'] ?? '') === $lic['id']);

// 事件保留：制造 5 条（2 条旧）
for ($i = 0; $i < 5; $i++) {
    $mtApp->events->insert(['type' => 't' . $i, 'payload' => []]);
}
foreach ($mtApp->events->all() as $id => $e) {
    if (str_starts_with((string) $e['type'], 't0') || str_starts_with((string) $e['type'], 't1')) {
        $mtApp->events->update((string) $id, ['created_at' => date('c', time() - 100 * 86400)]);
    }
}
$pruned = $mtApp->events->prune(30, 3);
check('事件按天数+行数清理', $pruned >= 2 && count($mtApp->events->all()) <= 3, "pruned={$pruned} left=" . count($mtApp->events->all()));
rrmdir($tmpMt);


echo "\n[平台治理 · 限流/沙箱/版本]\n";
$tmpG = sys_get_temp_dir() . '/pf-gov-' . bin2hex(random_bytes(4));
$gCfg = $cpCfg;
$gCfg['data_dir'] = $tmpG;
$gCfg['api'] = ['enabled' => true, 'key_prefix' => 'pfk_', 'rate_limit' => ['enabled' => true, 'per_minute' => 100]];
$gApp = new \PayFlow\Application($gCfg);
$gApp->products->create(['name' => '治理品', 'slug' => 'gov', 'type' => 'one_time', 'amount_cents' => 2000, 'entitlement' => ['kind' => 'content', 'items' => []]]);
$gid = (string) $gApp->products->findBySlug('gov')['id'];
$kid = 'pfk_govtest';
$sec = 'govsecret';
$gApp->apiKeys->insert(['name' => 'gov', 'key_id' => $kid, 'secret_hash' => password_hash($sec, PASSWORD_DEFAULT), 'secret_signing' => $sec, 'active' => true, 'mode' => 'test']);

$rl = new \PayFlow\Service\RateLimiter($gApp->rateLimits, ['api' => ['rate_limit' => ['enabled' => true, 'per_minute' => 2]]]);
check('限流前两次允许', $rl->check('k')['allowed'] === true && $rl->check('k')['allowed'] === true);
check('限流第三次拒绝', $rl->check('k')['allowed'] === false);

$ctrl = new \PayFlow\Http\Controller\ApiController($gApp);
$vreq = new \PayFlow\Http\Request('GET', '/api/v1/version', [], [], ['Authorization' => 'Bearer ' . $kid . '.' . $sec], '');
$vresp = $ctrl->version($vreq);
$vbody = json_decode($vresp->body, true);
check('版本端点返回 API 版本与模式', ($vbody['api']['current'] ?? '') === 'v1' && ($vbody['mode'] ?? '') === 'test');

$creq = new \PayFlow\Http\Request('POST', '/api/v1/checkout', [], ['product' => $gid, 'email' => 'gov@test.com', 'channel' => 'manual'], ['Authorization' => 'Bearer ' . $kid . '.' . $sec], '');
$cresp = $ctrl->checkout($creq);
$cbody = json_decode($cresp->body, true);
check('沙箱下单标记 test', ($cbody['order']['test'] ?? false) === true);
$gorder = $gApp->orders->findByOrderNo((string) ($cbody['order']['order_no'] ?? ''));
$gApp->orderService->markPaid((string) $gorder['id'], 'G1', (int) $gorder['amount_cents'], []);
$gsum = $gApp->analyticsService->summary(30);
check('看板排除沙箱订单', (int) $gsum['paid'] === 0 && (int) $gsum['gmv_cents'] === 0);
rrmdir($tmpG);

$csrfAuth = new \PayFlow\Http\AdminAuth(['admin' => ['username' => 'admin', 'password' => 'x', 'session_key' => 'pf_admin_test']]);
$csrfTok = $csrfAuth->csrfToken();
check('CSRF 令牌可生成', $csrfTok !== '');
check('CSRF 正确令牌通过', $csrfAuth->verifyCsrf(new \PayFlow\Http\Request('POST', '/admin/products', [], ['_csrf' => $csrfTok], [], '')));
check('CSRF 错误令牌拒绝', !$csrfAuth->verifyCsrf(new \PayFlow\Http\Request('POST', '/admin/products', [], [], ['X-CSRF-Token' => 'bad'], '')));

echo "\n" . str_repeat('─', 40) . "\n";
echo "通过 {$passed} · 失败 {$failed}\n";
exit($failed === 0 ? 0 : 1);
