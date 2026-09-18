<?php
$active = 'customers';
require __DIR__ . '/../partials/admin-head.php';
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>

<section class="page-head">
  <div>
    <h1>客户与权益</h1>
    <p class="sub">共 <?= (int) $total ?> 位 · 购买即会员，权益挂内容 URL 白名单。</p>
  </div>
</section>

<div class="pf-card">
  <div class="table-wrap">
    <table class="pf-table">
      <thead><tr><th>邮箱</th><th>称呼</th><th>会员等级</th><th>会员到期</th><th>external_id</th><th>创建时间</th></tr></thead>
      <tbody>
      <?php foreach ($customers as $c): ?>
        <tr>
          <td><?= pf_e((string) ($c['email'] ?? '')) ?></td>
          <td><?= pf_e((string) ($c['name'] ?? '')) ?></td>
          <td><?php if (!empty($c['membership_level'])): ?><span class="pf-pill accent"><?= pf_e((string) $c['membership_level']) ?></span><?php else: ?><span class="pf-faint">—</span><?php endif; ?></td>
          <td class="pf-faint nowrap"><?= pf_e((string) ($c['membership_expires_at'] ?? '—')) ?></td>
          <td class="mono pf-faint"><?= pf_e((string) ($c['external_id'] ?? '—')) ?></td>
          <td class="pf-faint nowrap"><?= pf_e(date('Y-m-d H:i', strtotime((string) $c['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($customers === []): ?><tr><td colspan="6" class="empty">暂无客户</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?= pf_pager($page, $pages, '/admin/customers') ?>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
