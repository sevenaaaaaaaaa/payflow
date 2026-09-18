<?php
$active = 'analytics';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;

$max = 1;
foreach ($series as $row) { $max = max($max, (int) $row['gmv_cents']); }
?>

<section class="page-head">
  <div>
    <h1 style="font-size:24px;font-weight:700">数据看板</h1>
    <p class="pf-muted" style="margin-top:6px">近 <?= (int) $days ?> 天。</p>
  </div>
  <div style="display:flex;gap:8px">
    <?php foreach ([7, 30, 90] as $d): ?>
      <a class="pf-btn <?= (int) $days === $d ? '' : 'ghost' ?> sm" href="<?= pf_url('/admin/analytics?days=' . $d) ?>"><?= $d ?> 天</a>
    <?php endforeach; ?>
  </div>
</section>

<section class="pf-grid cols-3" style="margin-bottom:22px">
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['gmv_cents'])) ?></span><span class="l">GMV</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['aov_cents'])) ?></span><span class="l">客单价</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $summary['paid'] ?> / <?= (int) $summary['created'] ?></span><span class="l">支付 / 建单</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e((string) $summary['conversion_rate']) ?>%</span><span class="l">转化率</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['refund_cents'])) ?></span><span class="l">退款</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['discount_cents'])) ?></span><span class="l">优惠让利</span></div></div>
</section>

<div class="pf-grid cols-2" style="align-items:start;margin-bottom:22px">
  <div class="pf-card">
    <h3 style="margin-bottom:14px">近 <?= count($series) ?> 天 GMV</h3>
    <div style="display:flex;align-items:flex-end;gap:3px;height:140px">
      <?php foreach ($series as $row): ?>
        <div title="<?= pf_e((string) $row['date']) ?> · <?= pf_e(Money::yuan((int) $row['gmv_cents'])) ?>"
             style="flex:1;background:var(--accent);border-radius:3px 3px 0 0;min-height:2px;height:<?= max(2, (int) round((int) $row['gmv_cents'] / $max * 130)) ?>px"></div>
      <?php endforeach; ?>
    </div>
    <div class="pf-row-between pf-faint" style="margin-top:8px"><span><?= pf_e((string) ($series[0]['date'] ?? '')) ?></span><span><?= pf_e((string) (end($series)['date'] ?? '')) ?></span></div>
  </div>

  <div class="pf-card">
    <h3 style="margin-bottom:14px">订阅健康</h3>
    <div class="pf-grid cols-2">
      <div class="pf-stat"><span class="n"><?= (int) $summary['subscriptions']['active'] ?></span><span class="l">活跃</span></div>
      <div class="pf-stat"><span class="n"><?= (int) $summary['subscriptions']['past_due'] ?></span><span class="l">续费失败</span></div>
      <div class="pf-stat"><span class="n"><?= (int) $summary['subscriptions']['canceled'] ?></span><span class="l">已取消</span></div>
      <div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['subscriptions']['mrr_cents'])) ?></span><span class="l">MRR</span></div>
    </div>
    <hr style="border:none;border-top:1px solid var(--border-soft);margin:16px 0">
    <h3 style="margin-bottom:14px">佣金</h3>
    <div class="pf-grid cols-2">
      <div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['commission']['pending'])) ?></span><span class="l">待解冻</span></div>
      <div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['commission']['available'])) ?></span><span class="l">可提现</span></div>
      <div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['commission']['paid'])) ?></span><span class="l">已打款</span></div>
      <div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $summary['commission']['reversed'])) ?></span><span class="l">已冲正</span></div>
    </div>
  </div>
</div>

<div class="pf-card">
  <h3 style="margin-bottom:14px">渠道分布</h3>
  <div class="table-wrap"><table class="pf-table">
    <thead><tr><th>通道</th><th>支付单数</th><th>GMV</th></tr></thead>
    <tbody>
    <?php foreach ($summary['channels'] as $channel => $row): ?>
      <tr><td><?= pf_e((string) $channel) ?></td><td><?= (int) $row['count'] ?></td><td><?= pf_e(Money::yuan((int) $row['gmv'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($summary['channels'] === []): ?><tr><td colspan="3" class="pf-faint">区间内暂无支付</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
