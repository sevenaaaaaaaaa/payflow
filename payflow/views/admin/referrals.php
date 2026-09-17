<?php
$active = 'referrals';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;
?>

<section style="padding:28px 0 16px" class="pf-row-between">
  <div>
    <h1 style="font-size:24px;font-weight:700">推荐裂变</h1>
    <p class="pf-muted" style="margin-top:6px">推荐码 → 点击 → 归因 → 佣金（冻结后可提现）。</p>
  </div>
  <form method="post" action="<?= pf_url('/admin/referrals/new') ?>" class="pf-row-between" style="gap:8px">
    <input class="pf-input" name="email" type="email" placeholder="推荐人邮箱" required style="width:220px">
    <button class="pf-btn" type="submit">添加推荐人</button>
  </form>
</section>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>推荐人</th><th>推荐码</th><th>点击/成交</th><th>待解冻</th><th>可提现</th><th>累计佣金</th><th>推广链接</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): $r = $row['referral']; $s = $row['summary']; ?>
      <tr>
        <td><?= pf_e((string) $r['email']) ?><div class="pf-faint"><?= pf_e((string) $r['level']) ?></div></td>
        <td class="pf-mono"><?= pf_e((string) $r['code']) ?></td>
        <td><?= (int) ($r['clicks'] ?? 0) ?> / <?= (int) ($r['orders'] ?? 0) ?></td>
        <td><?= pf_e(Money::yuan((int) $s['pending'])) ?></td>
        <td><strong><?= pf_e(Money::yuan((int) $row['withdrawable'])) ?></strong></td>
        <td><?= pf_e(Money::yuan((int) $s['total'])) ?></td>
        <td style="max-width:320px">
          <div class="pf-mono" style="word-break:break-all;font-size:11.5px"><?= pf_e((string) $row['link']) ?></div>
          <a class="pf-btn ghost sm" style="margin-top:6px" href="<?= pf_e((string) $row['partner_url']) ?>" target="_blank" rel="noopener">推荐人自助页</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="7" class="pf-faint">还没有推荐人——任何买家在结账后都可成为推荐人。</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
