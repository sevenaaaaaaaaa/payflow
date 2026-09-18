<?php
/** @var string $title */
$active = $active ?? '';
$themeScript = "try{var t=localStorage.getItem('pf-theme');if(!t){t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'}document.documentElement.setAttribute('data-theme',t)}catch(e){}";
$navGroups = [
    ' ' => [
        'dashboard' => ['/admin', '概览'],
        'products' => ['/admin/products', '商品'],
        'subscriptions' => ['/admin/subscriptions', '订阅'],
        'orders' => ['/admin/orders', '订单'],
        'customers' => ['/admin/customers', '客户'],
    ],
    '增长' => [
        'coupons' => ['/admin/coupons', '优惠券'],
        'vouchers' => ['/admin/vouchers', '兑换券'],
        'payment-links' => ['/admin/payment-links', '支付链接'],
        'referrals' => ['/admin/referrals', '推荐'],
        'commissions' => ['/admin/commissions', '佣金'],
        'payouts' => ['/admin/payouts', '提现'],
    ],
    '系统' => [
        'analytics' => ['/admin/analytics', '看板'],
        'api-keys' => ['/admin/api-keys', 'API'],
        'licenses' => ['/admin/licenses', 'License'],
        'invoices' => ['/admin/invoices', '发票'],
        'webhooks' => ['/admin/webhooks', 'Webhook'],
        'audit' => ['/admin/audit', '审计'],
    ],
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
<link rel="stylesheet" href="<?= pf_asset('admin.css') ?>">
<meta name="csrf-token" content="<?= pf_e(pf_csrf_token()) ?>">
<script><?= $themeScript ?></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) {
    document.querySelectorAll('form').forEach(function (f) {
      if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
      if (f.querySelector('input[name="_csrf"]')) { return; }
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = '_csrf'; i.value = meta.content;
      f.appendChild(i);
    });
  }
});
</script>
</head>
<body>
<div class="pf-wrap">
  <div class="pf-nav">
    <a class="pf-brand" href="<?= pf_url('/admin') ?>"><span class="dot"></span>PayFlow · 后台</a>
    <nav class="admin-nav" aria-label="后台导航">
      <?php foreach ($navGroups as $group => $items): ?>
        <span class="admin-navgroup">
          <?php if (trim($group) !== ''): ?><span class="admin-navlabel"><?= pf_e($group) ?></span><?php endif; ?>
          <?php foreach ($items as $key => [$href, $label]): ?>
            <a class="pf-btn <?= $active === $key ? '' : 'ghost' ?> sm" href="<?= pf_url($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= pf_e($label) ?></a>
          <?php endforeach; ?>
        </span>
      <?php endforeach; ?>
    </nav>
    <div class="page-actions">
      <a class="pf-btn ghost sm" href="<?= pf_url('/store') ?>" target="_blank" rel="noopener">看店铺</a>
      <form method="post" action="<?= pf_url('/admin/logout') ?>" style="display:inline">
        <button class="pf-btn ghost sm" type="submit">退出</button>
      </form>
    </div>
  </div>
