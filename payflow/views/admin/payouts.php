<?php
$active = 'payouts';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;

$badge = static fn (string $s): string => match ($s) {
    'requested' => 'warn',
    'approved' => 'accent',
    'paid' => 'ok',
    'rejected' => 'danger',
    default => 'neutral',
};
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">佣金明细</h1>
  <p class="pf-muted" style="margin-top:6px">pending 冻结 → available 可提现 → paid 已打款；退款自动 reversed。</p>
</section>

<div class="pf-card" style="margin-bottom:22px">
  <table class="pf-table">
    <thead><tr><th>时间</th><th>推荐人</th><th>订单</th><th>基数</th><th>比例</th><th>佣金</th><th>状态</th><th>可提现时间</th></tr></thead>
    <tbody>
    <?php foreach ($commissions as $c): ?>
      <tr>
        <td class="pf-faint"><?= pf_e(substr((string) $c['created_at'], 0, 16)) ?></td>
        <td><?= pf_e((string) ($c['referrer_email'] ?? '')) ?></td>
        <td class="pf-mono"><?= pf_e((string) ($c['order_no'] ?? '')) ?></td>
        <td><?= pf_e(Money::yuan((int) $c['base_amount_cents'])) ?></td>
        <td><?= pf_e((string) round(((float) $c['rate']) * 100, 1)) ?>%</td>
        <td><strong><?= pf_e(Money::yuan((int) $c['amount_cents'])) ?></strong></td>
        <td><span class="pf-pill <?= $badge((string) $c['status']) ?>"><?= pf_e((string) $c['status']) ?></span></td>
        <td class="pf-faint"><?= pf_e(substr((string) ($c['available_at'] ?? ''), 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($commissions === []): ?><tr><td colspan="8" class="pf-faint">暂无佣金</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<section style="padding:8px 0 16px">
  <h1 style="font-size:24px;font-weight:700">提现审核</h1>
</section>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>时间</th><th>推荐人</th><th>金额</th><th>方式/账号</th><th>状态</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($payouts as $p): ?>
      <tr>
        <td class="pf-faint"><?= pf_e(substr((string) $p['created_at'], 0, 16)) ?></td>
        <td><?= pf_e((string) ($p['email'] ?? '')) ?></td>
        <td><strong><?= pf_e(Money::yuan((int) $p['amount_cents'])) ?></strong></td>
        <td><?= pf_e((string) ($p['method'] ?? '')) ?> · <span class="pf-mono"><?= pf_e((string) ($p['account'] ?? '')) ?></span></td>
        <td><span class="pf-pill <?= $badge((string) $p['status']) ?>"><?= pf_e((string) $p['status']) ?></span>
          <?php if (!empty($p['transfer_no'])): ?><div class="pf-faint">单号 <?= pf_e((string) $p['transfer_no']) ?></div><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if (($p['status'] ?? '') === 'requested'): ?>
            <form method="post" action="<?= pf_url('/admin/payouts/' . (string) $p['id'] . '/approve') ?>" style="display:inline"><button class="pf-btn sm" type="submit">批准</button></form>
            <form method="post" action="<?= pf_url('/admin/payouts/' . (string) $p['id'] . '/reject') ?>" style="display:inline"><button class="pf-btn ghost sm" type="submit">驳回</button></form>
          <?php elseif (($p['status'] ?? '') === 'approved'): ?>
            <form method="post" action="<?= pf_url('/admin/payouts/' . (string) $p['id'] . '/pay') ?>" style="display:inline" class="pf-row-between">
              <input class="pf-input" name="transfer_no" placeholder="打款单号" style="width:150px;height:34px">
              <button class="pf-btn sm" type="submit">标记已打款</button>
            </form>
          <?php else: ?>
            <span class="pf-faint">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($payouts === []): ?><tr><td colspan="6" class="pf-faint">暂无提现申请</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
