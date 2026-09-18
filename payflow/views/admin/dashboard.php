<?php $active = 'dashboard'; require __DIR__ . '/../partials/admin-head.php'; ?>

<section class="page-head">
  <h1 style="font-size:24px;font-weight:700">概览</h1>
  <p class="pf-muted" style="margin-top:6px">收款、订阅、客户的实时状态。</p>
</section>

<section class="pf-grid cols-3" style="margin-bottom:22px">
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(\PayFlow\Support\Money::yuan((int) $stats['revenue_cents'])) ?></span><span class="l">累计 GMV（已支付+已交付）</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $stats['paid'] ?> / <?= (int) $stats['total'] ?></span><span class="l">已支付 / 总订单</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $stats['subscriptions'] ?></span><span class="l">订阅订单</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $productCount ?></span><span class="l">商品数</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $customerCount ?></span><span class="l">客户数</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(\PayFlow\Support\Money::yuan((int) $stats['refunded_cents'])) ?></span><span class="l">累计退款</span></div></div>
</section>

<div class="pf-grid" style="grid-template-columns:1.3fr 1fr;gap:20px;align-items:start">
  <div class="pf-card">
    <div class="pf-row-between" style="margin-bottom:12px">
      <h3>最近订单</h3><a class="pf-btn ghost sm" href="<?= pf_url('/admin/orders') ?>">全部</a>
    </div>
    <div class="table-wrap"><table class="pf-table">
      <thead><tr><th>订单号</th><th>商品</th><th>金额</th><th>状态</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $order): ?>
        <tr>
          <td class="pf-mono"><?= pf_e((string) $order['order_no']) ?></td>
          <td><?= pf_e((string) ($order['product_name'] ?? '')) ?></td>
          <td><?= pf_e(\PayFlow\Support\Money::yuan((int) $order['amount_cents'])) ?></td>
          <td><span class="pf-pill <?= \PayFlow\Domain\OrderStateMachine::isPaidLike((string) $order['status']) ? 'ok' : 'neutral' ?>"><?= pf_e(\PayFlow\Domain\OrderStateMachine::label((string) $order['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($orders === []): ?><tr><td colspan="4" class="pf-faint">暂无订单</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <div class="pf-card">
    <h3 style="margin-bottom:12px">事件流</h3>
    <div class="pf-list">
      <?php foreach ($events as $event): ?>
        <div class="pf-row-between" style="font-size:13px;border-bottom:1px solid var(--border-soft);padding-bottom:8px">
          <span class="pf-mono"><?= pf_e((string) $event['type']) ?></span>
          <span class="pf-faint"><?= pf_e(date('m-d H:i', strtotime((string) $event['created_at']))) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if ($events === []): ?><span class="pf-faint">暂无事件</span><?php endif; ?>
    </div>
  </div>
</div>

<section style="margin-top:22px">
  <div class="pf-row-between" style="margin-bottom:12px"><h3>订阅</h3></div>
  <div class="pf-card">
    <div class="table-wrap"><table class="pf-table">
      <thead><tr><th>客户</th><th>状态</th><th>周期</th><th>下次续费</th><th>失败次数</th></tr></thead>
      <tbody>
      <?php foreach ($subscriptions as $sub): ?>
        <tr>
          <td><?= pf_e((string) ($sub['email'] ?? '')) ?></td>
          <td><span class="pf-pill <?= ($sub['status'] ?? '') === 'active' ? 'ok' : 'warn' ?>"><?= pf_e((string) ($sub['status'] ?? '')) ?></span></td>
          <td><?= pf_e(\PayFlow\Support\Money::yuan((int) ($sub['amount_cents'] ?? 0))) ?> / <?= ($sub['interval'] ?? 'month') === 'year' ? '年' : '月' ?></td>
          <td class="pf-faint"><?= pf_e((string) ($sub['current_period_end'] ?? '')) ?></td>
          <td><?= (int) ($sub['failed_attempts'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($subscriptions === []): ?><tr><td colspan="5" class="pf-faint">暂无订阅</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</section>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
