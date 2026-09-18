<?php
$active = 'licenses';
require __DIR__ . '/../partials/admin-head.php';
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>

<section class="page-head">
  <div>
    <h1>License 密钥</h1>
    <p class="sub">共 <?= (int) $total ?> 条 · 商品开启 License 后，交付时自动签发。</p>
  </div>
</section>

<div class="pf-card">
  <div class="table-wrap">
    <table class="pf-table">
      <thead><tr><th>密钥</th><th>订单</th><th>客户</th><th>状态</th><th>签发时间</th></tr></thead>
      <tbody>
      <?php foreach ($licenses as $l): ?>
        <tr>
          <td class="mono"><?= pf_e((string) $l['license_key']) ?></td>
          <td class="mono"><?= pf_e((string) ($l['order_no'] ?? '')) ?></td>
          <td><?= pf_e((string) ($l['email'] ?? '')) ?></td>
          <td><span class="pf-pill <?= ($l['status'] ?? '') === 'active' ? 'ok' : 'neutral' ?>"><?= pf_e((string) $l['status']) ?></span></td>
          <td class="pf-faint nowrap"><?= pf_e(substr((string) $l['created_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($licenses === []): ?><tr><td colspan="5" class="empty">暂无 License</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?= pf_pager($page, $pages, '/admin/licenses') ?>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
