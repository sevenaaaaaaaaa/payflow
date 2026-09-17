<?php
$active = 'licenses';
require __DIR__ . '/../partials/admin-head.php';
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">License 密钥</h1>
  <p class="pf-muted" style="margin-top:6px">商品开启 License 后，每笔订单交付时自动签发。</p>
</section>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>密钥</th><th>订单</th><th>客户</th><th>状态</th><th>签发时间</th></tr></thead>
    <tbody>
    <?php foreach ($licenses as $license): ?>
      <tr>
        <td class="pf-mono"><?= pf_e((string) $license['license_key']) ?></td>
        <td class="pf-mono"><?= pf_e((string) ($license['order_no'] ?? '')) ?></td>
        <td><?= pf_e((string) ($license['email'] ?? '')) ?></td>
        <td><span class="pf-pill <?= ($license['status'] ?? '') === 'active' ? 'ok' : 'neutral' ?>"><?= pf_e((string) $license['status']) ?></span></td>
        <td class="pf-faint"><?= pf_e(substr((string) $license['created_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($licenses === []): ?><tr><td colspan="5" class="pf-faint">暂无 License</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
