<?php require __DIR__ . '/partials/head.php'; ?>

<section class="pf-hero pf-center">
  <span class="pf-eyebrow">支付结果</span>
  <h1 style="font-size:28px;margin-top:14px"><?= pf_e((string) $order['status_label']) ?></h1>
  <p class="pf-muted" style="margin-top:8px">订单 <?= pf_e((string) $order['order_no']) ?> · <?= pf_e((string) $order['amount']) ?></p>
</section>

<div class="pf-card pf-narrow" style="margin:0 auto">
  <div class="pf-row-between">
    <span class="pf-muted">商品</span><strong><?= pf_e((string) $order['product_name']) ?></strong>
  </div>
  <hr style="border:none;border-top:1px solid var(--border-soft);margin:14px 0">
  <?php if ($delivery_url): ?>
    <div class="pf-notice ok" style="margin-bottom:14px">支付已确认，内容已就绪。</div>
    <a class="pf-btn block" href="<?= pf_e($delivery_url) ?>">查看交付内容</a>
  <?php else: ?>
    <div class="pf-notice warn">尚未确认到账。若已支付，请稍候刷新；异步通知可能有延迟。</div>
    <a class="pf-btn ghost block" style="margin-top:12px" href="<?= pf_url('/return/' . $token) ?>">刷新</a>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
