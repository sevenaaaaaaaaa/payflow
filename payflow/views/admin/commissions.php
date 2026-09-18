<?php
$active = 'commissions';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;
?>

<section style="padding:28px 0 16px" class="pf-row-between">
  <div>
    <h1 style="font-size:24px;font-weight:700">佣金结算报表</h1>
    <p class="pf-muted" style="margin-top:6px">按时间范围汇总，可导出对账单 CSV。</p>
  </div>
  <form method="get" action="<?= pf_url('/admin/commissions') ?>" class="pf-row-between" style="gap:8px">
    <input class="pf-input" type="date" name="from" value="<?= pf_e((string) $from) ?>" style="width:150px">
    <span class="pf-faint">~</span>
    <input class="pf-input" type="date" name="to" value="<?= pf_e((string) $to) ?>" style="width:150px">
    <button class="pf-btn ghost" type="submit">筛选</button>
    <a class="pf-btn" href="<?= pf_url('/admin/commissions/export?from=' . rawurlencode((string) $from) . '&to=' . rawurlencode((string) $to)) ?>">导出 CSV</a>
  </form>
</section>

<section class="pf-grid cols-3" style="margin-bottom:20px">
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['pending'])) ?></span><span class="l">待解冻</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['available'])) ?></span><span class="l">可提现</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['paid'])) ?></span><span class="l">已打款</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['reversed'])) ?></span><span class="l">已冲正</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $total)) ?></span><span class="l">合计</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= count($detail) ?></span><span class="l">明细条数</span></div></div>
</section>

<div class="pf-card" style="margin-bottom:20px">
  <h3 style="margin-bottom:12px">按推荐人汇总</h3>
  <table class="pf-table">
    <thead><tr><th>推荐人</th><th>订单数</th><th>佣金合计</th></tr></thead>
    <tbody>
    <?php foreach ($byReferrer as $row): ?>
      <tr><td><?= pf_e((string) $row['email']) ?></td><td><?= (int) $row['count'] ?></td><td><?= pf_e(Money::yuan((int) $row['amount'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($byReferrer === []): ?><tr><td colspan="3" class="pf-faint">区间内无佣金</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="pf-card">
  <h3 style="margin-bottom:12px">明细</h3>
  <table class="pf-table">
    <thead><tr><th>时间</th><th>订单</th><th>推荐人</th><th>订单金额</th><th>比例</th><th>佣金</th><th>状态</th></tr></thead>
    <tbody>
    <?php foreach ($detail as $c): ?>
      <tr>
        <td class="pf-faint"><?= pf_e(substr((string) ($c['created_at'] ?? ''), 0, 16)) ?></td>
        <td class="pf-mono"><?= pf_e((string) ($c['order_no'] ?? '')) ?></td>
        <td><?= pf_e((string) ($c['referrer_email'] ?? '')) ?></td>
        <td><?= pf_e(Money::yuan((int) ($c['base_amount_cents'] ?? 0))) ?></td>
        <td><?= pf_e((string) round(((float) ($c['rate'] ?? 0)) * 100, 1)) ?>%</td>
        <td><strong><?= pf_e(Money::yuan((int) ($c['amount_cents'] ?? 0))) ?></strong></td>
        <td><span class="pf-pill <?= ($c['status'] ?? '') === 'paid' ? 'ok' : (($c['status'] ?? '') === 'available' ? 'accent' : (($c['status'] ?? '') === 'reversed' ? 'danger' : 'neutral')) ?>"><?= pf_e((string) ($c['status'] ?? '')) ?></span></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($detail === []): ?><tr><td colspan="7" class="pf-faint">区间内无明细</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
