<?php
$active = 'invoices';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">发票 / 收据</h1>
  <p class="pf-muted" style="margin-top:6px">订单交付时自动生成，凭签名链接查看与打印。</p>
</section>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>编号</th><th>订单</th><th>客户</th><th>金额</th><th>开具时间</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): $inv = $row['invoice']; ?>
      <tr>
        <td class="pf-mono"><?= pf_e((string) $inv['number']) ?></td>
        <td class="pf-mono"><?= pf_e((string) ($inv['order_no'] ?? '')) ?></td>
        <td><?= pf_e((string) ($inv['email'] ?? '')) ?></td>
        <td><?= pf_e(Money::yuan((int) $inv['amount_cents'])) ?></td>
        <td class="pf-faint"><?= pf_e(substr((string) $inv['issued_at'], 0, 16)) ?></td>
        <td><a class="pf-btn ghost sm" href="<?= pf_e((string) $row['url']) ?>" target="_blank" rel="noopener">查看</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="6" class="pf-faint">暂无发票</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
