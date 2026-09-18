<?php
$active = 'invoices';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>

<section class="page-head">
  <div>
    <h1>发票 / 收据</h1>
    <p class="sub">共 <?= (int) $total ?> 张 · 订单交付时自动生成，凭签名链接查看与打印。</p>
  </div>
</section>

<div class="pf-card">
  <div class="table-wrap">
    <table class="pf-table">
      <thead><tr><th>编号</th><th>订单</th><th>客户</th><th class="num">金额</th><th>开具时间</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $inv = $row['invoice']; ?>
        <tr>
          <td class="mono"><?= pf_e((string) $inv['number']) ?></td>
          <td class="mono"><?= pf_e((string) ($inv['order_no'] ?? '')) ?></td>
          <td><?= pf_e((string) ($inv['email'] ?? '')) ?></td>
          <td class="num"><?= pf_e(Money::yuan((int) $inv['amount_cents'])) ?></td>
          <td class="pf-faint nowrap"><?= pf_e(substr((string) $inv['issued_at'], 0, 16)) ?></td>
          <td class="nowrap"><a class="pf-btn ghost sm" href="<?= pf_e((string) $row['url']) ?>" target="_blank" rel="noopener">查看</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="6" class="empty">暂无发票</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?= pf_pager($page, $pages, '/admin/invoices') ?>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
