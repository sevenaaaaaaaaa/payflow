<?php
$active = 'coupons';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>

<section class="page-head">
  <div>
    <h1>优惠券</h1>
    <p class="sub">共 <?= (int) $total ?> 张 · 满减 / 折扣 / 限时 / 限量。</p>
  </div>
  <div class="page-actions"><a class="pf-btn" href="<?= pf_url('/admin/coupons/new') ?>">新建优惠券</a></div>
</section>

<div class="pf-card">
  <div class="table-wrap">
    <table class="pf-table">
      <thead><tr><th>码</th><th>优惠</th><th class="num">门槛</th><th class="num">已用 / 上限</th><th>有效期</th><th>状态</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($coupons as $coupon): ?>
        <tr>
          <td><strong class="mono"><?= pf_e((string) $coupon['code']) ?></strong><div class="pf-faint"><?= pf_e((string) ($coupon['description'] ?? '')) ?></div></td>
          <td><?= ($coupon['type'] ?? '') === 'percent' ? (int) $coupon['value'] . '% off' : Money::yuan((int) $coupon['value']) . ' off' ?></td>
          <td class="num"><?= (int) ($coupon['min_amount_cents'] ?? 0) > 0 ? Money::yuan((int) $coupon['min_amount_cents']) : '—' ?></td>
          <td class="num"><?= (int) ($coupon['redeemed_count'] ?? 0) ?> / <?= (int) ($coupon['max_redemptions'] ?? 0) === 0 ? '∞' : (int) $coupon['max_redemptions'] ?></td>
          <td class="pf-faint nowrap"><?= pf_e(($coupon['starts_at'] ?? '') !== '' ? substr((string) $coupon['starts_at'], 0, 10) : '—') ?> ~ <?= pf_e(($coupon['expires_at'] ?? '') !== '' ? substr((string) $coupon['expires_at'], 0, 10) : '—') ?></td>
          <td><span class="pf-pill <?= ($coupon['active'] ?? true) ? 'ok' : 'neutral' ?>"><?= ($coupon['active'] ?? true) ? '启用' : '停用' ?></span></td>
          <td class="nowrap">
            <a class="pf-btn ghost sm" href="<?= pf_url('/admin/coupons/' . (string) $coupon['id'] . '/edit') ?>">编辑</a>
            <form method="post" action="<?= pf_url('/admin/coupons/' . (string) $coupon['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('删除该优惠券？')">
              <button class="pf-btn ghost sm" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($coupons === []): ?><tr><td colspan="7" class="empty">还没有优惠券</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?= pf_pager($page, $pages, '/admin/coupons') ?>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
