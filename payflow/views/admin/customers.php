<?php $active = 'customers'; require __DIR__ . '/../partials/admin-head.php'; ?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">客户与权益</h1>
  <p class="pf-muted" style="margin-top:6px">购买即会员，权益挂内容 URL 白名单。</p>
</section>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>邮箱</th><th>称呼</th><th>会员等级</th><th>会员到期</th><th>创建时间</th></tr></thead>
    <tbody>
    <?php foreach ($customers as $customer): ?>
      <tr>
        <td><?= pf_e((string) ($customer['email'] ?? '')) ?></td>
        <td><?= pf_e((string) ($customer['name'] ?? '')) ?></td>
        <td><?php if (!empty($customer['membership_level'])): ?><span class="pf-pill accent"><?= pf_e((string) $customer['membership_level']) ?></span><?php else: ?><span class="pf-faint">—</span><?php endif; ?></td>
        <td class="pf-faint"><?= pf_e((string) ($customer['membership_expires_at'] ?? '—')) ?></td>
        <td class="pf-faint"><?= pf_e(date('Y-m-d H:i', strtotime((string) $customer['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($customers === []): ?><tr><td colspan="5" class="pf-faint">暂无客户</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
