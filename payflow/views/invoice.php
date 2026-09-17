<?php
require __DIR__ . '/partials/head.php';
use PayFlow\Support\Money;
?>

<section style="padding:34px 0 12px" class="pf-row-between">
  <div>
    <span class="pf-eyebrow">发票 / 收据</span>
    <h1 style="font-size:26px;font-weight:700;margin-top:14px"><?= pf_e((string) $invoice['number']) ?></h1>
    <p class="pf-muted" style="margin-top:6px">开票日期 <?= pf_e(date('Y-m-d', strtotime((string) $invoice['issued_at']))) ?></p>
  </div>
  <button class="pf-btn ghost" onclick="window.print()">打印</button>
</section>

<div class="pf-card">
  <div class="pf-grid cols-2" style="margin-bottom:18px">
    <div>
      <div class="pf-faint">收款方</div>
      <div><strong><?= pf_e((string) $invoice['seller_name']) ?></strong></div>
      <?php if (!empty($invoice['seller_tax_id'])): ?><div class="pf-faint">税号 <?= pf_e((string) $invoice['seller_tax_id']) ?></div><?php endif; ?>
      <?php if (!empty($invoice['seller_address'])): ?><div class="pf-faint"><?= pf_e((string) $invoice['seller_address']) ?></div><?php endif; ?>
    </div>
    <div>
      <div class="pf-faint">付款方</div>
      <div><strong><?= pf_e((string) ($invoice['name'] ?: $invoice['email'])) ?></strong></div>
      <div class="pf-faint"><?= pf_e((string) $invoice['email']) ?></div>
      <?php if ($order !== null): ?><div class="pf-faint">订单 <?= pf_e((string) $order['order_no']) ?></div><?php endif; ?>
    </div>
  </div>

  <table class="pf-table">
    <thead><tr><th>项目</th><th style="text-align:right">金额</th></tr></thead>
    <tbody>
      <?php foreach ($invoice['items'] as $item): ?>
        <tr><td><?= pf_e((string) $item['name']) ?></td><td style="text-align:right"><?= pf_e(Money::yuan((int) $item['amount_cents'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($order !== null && (int) ($order['discount_cents'] ?? 0) > 0): ?>
        <tr><td>优惠（<?= pf_e((string) ($order['coupon_code'] ?? '')) ?>）</td><td style="text-align:right;color:var(--ok)">-<?= pf_e(Money::yuan((int) $order['discount_cents'])) ?></td></tr>
      <?php endif; ?>
      <tr><td><strong>合计</strong></td><td style="text-align:right"><strong><?= pf_e(Money::yuan((int) $invoice['amount_cents'])) ?></strong></td></tr>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
