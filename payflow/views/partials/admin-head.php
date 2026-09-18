<?php
/** @var string $title */
$active = $active ?? '';
$themeScript = "try{var t=localStorage.getItem('pf-theme');if(!t){t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'}document.documentElement.setAttribute('data-theme',t)}catch(e){}";
$nav = [
    'dashboard' => ['/admin', '概览'],
    'products' => ['/admin/products', '商品'],
    'subscriptions' => ['/admin/subscriptions', '订阅'],
    'orders' => ['/admin/orders', '订单'],
    'payment-links' => ['/admin/payment-links', '支付链接'],
    'coupons' => ['/admin/coupons', '优惠券'],
    'vouchers' => ['/admin/vouchers', '兑换券'],
    'referrals' => ['/admin/referrals', '推荐'],
    'commissions' => ['/admin/commissions', '佣金'],
    'payouts' => ['/admin/payouts', '提现'],
    'customers' => ['/admin/customers', '客户'],
    'analytics' => ['/admin/analytics', '看板'],
    'api-keys' => ['/admin/api-keys', 'API'],
    'licenses' => ['/admin/licenses', 'License'],
    'invoices' => ['/admin/invoices', '发票'],
    'webhooks' => ['/admin/webhooks', 'Webhook'],
    'audit' => ['/admin/audit', '审计'],
];
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= pf_e($title ?? 'PayFlow 后台') ?></title>
<link rel="stylesheet" href="<?= pf_asset('tokens.css') ?>">
<link rel="stylesheet" href="<?= pf_asset('modules.css') ?>">
<link rel="stylesheet" href="<?= pf_asset('checkout.css') ?>">
<meta name="csrf-token" content="<?= pf_e(pf_csrf_token()) ?>">
<script><?= $themeScript ?></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (!meta) { return; }
  document.querySelectorAll('form').forEach(function (f) {
    if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
    if (f.querySelector('input[name="_csrf"]')) { return; }
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = '_csrf'; i.value = meta.content;
    f.appendChild(i);
  });
});
</script>
</head>
<body>
<div class="pf-wrap">
  <div class="pf-nav">
    <a class="pf-brand" href="<?= pf_url('/admin') ?>"><span class="dot"></span>PayFlow · 后台</a>
    <nav style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <a class="pf-btn <?= $active === $key ? '' : 'ghost' ?> sm" href="<?= pf_url($href) ?>"><?= pf_e($label) ?></a>
      <?php endforeach; ?>
      <a class="pf-btn ghost sm" href="<?= pf_url('/store') ?>" target="_blank" rel="noopener">看店铺</a>
      <form method="post" action="<?= pf_url('/admin/logout') ?>" style="display:inline">
        <button class="pf-btn ghost sm" type="submit">退出</button>
      </form>
    </nav>
  </div>
