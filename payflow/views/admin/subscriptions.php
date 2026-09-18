<?php
$active = 'subscriptions';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;
?>

<section class="page-head">
  <div>
    <h1 style="font-size:24px;font-weight:700">订阅</h1>
    <p class="pf-muted" style="margin-top:6px">自动续费 · 失败重试（1/3/5 天）· 宽限期后降级。</p>
  </div>
  <form method="post" action="<?= pf_url('/admin/cron/run') ?>">
    <button class="pf-btn" type="submit">立即跑定时任务</button>
  </form>
</section>

<section class="pf-grid cols-3" style="margin-bottom:20px">
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $stats['active'] ?></span><span class="l">活跃订阅</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $stats['past_due'] ?></span><span class="l">续费失败重试中</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $stats['mrr_cents'])) ?></span><span class="l">MRR（预估）</span></div></div>
</section>

<div class="pf-card">
  <div class="table-wrap"><table class="pf-table">
    <thead><tr><th>客户</th><th>商品</th><th>金额</th><th>状态</th><th>当前周期至</th><th>失败</th><th>下次重试</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($subscriptions as $sub): ?>
      <?php $status = (string) ($sub['status'] ?? ''); ?>
      <tr>
        <td><?= pf_e((string) ($sub['email'] ?? '')) ?></td>
        <td><?= pf_e((string) ($sub['product_name'] ?? '')) ?></td>
        <td><?= pf_e(Money::yuan((int) ($sub['amount_cents'] ?? 0))) ?> / <?= ($sub['interval'] ?? 'month') === 'year' ? '年' : '月' ?></td>
        <td><span class="pf-pill <?= $status === 'active' ? 'ok' : ($status === 'past_due' ? 'warn' : 'neutral') ?>"><?= pf_e($status) ?></span></td>
        <td class="pf-faint"><?= pf_e(substr((string) ($sub['current_period_end'] ?? ''), 0, 10)) ?></td>
        <td><?= (int) ($sub['failed_attempts'] ?? 0) ?></td>
        <td class="pf-faint"><?= pf_e((string) ($sub['next_retry_at'] ?? '—')) ?></td>
        <td>
          <?php if (in_array($status, ['active', 'past_due'], true)): ?>
            <form method="post" action="<?= pf_url('/admin/subscriptions/' . (string) $sub['id'] . '/cancel') ?>" style="display:inline" onsubmit="return confirm('取消该订阅？')">
              <button class="pf-btn ghost sm" type="submit">取消</button>
            </form>
          <?php else: ?>
            <span class="pf-faint">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($subscriptions === []): ?><tr><td colspan="8" class="pf-faint">暂无订阅</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
