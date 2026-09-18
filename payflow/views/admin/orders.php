<?php
$active = 'orders';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Domain\OrderStateMachine;
use PayFlow\Support\Money;

$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>

<section style="padding:28px 0 16px" class="pf-row-between">
  <div>
    <h1 style="font-size:24px;font-weight:700">订单</h1>
    <p class="pf-muted" style="margin-top:6px">共 <?= (int) $total ?> 笔 · created → paid → delivered → refunded，全程可审计。</p>
  </div>
  <div style="display:flex;gap:8px">
    <form method="get" action="<?= pf_url('/admin/orders') ?>" class="pf-row-between" style="gap:8px">
      <input class="pf-input" name="q" value="<?= pf_e((string) $q) ?>" placeholder="订单号/邮箱/商品/状态" style="width:240px">
      <button class="pf-btn ghost" type="submit">搜索</button>
    </form>
    <a class="pf-btn ghost" href="<?= pf_url('/admin/orders/export' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">导出 CSV</a>
  </div>
</section>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>订单号</th><th>客户</th><th>商品</th><th>金额</th><th>优惠</th><th>通道</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $order): ?>
      <?php $status = (string) $order['status']; ?>
      <tr>
        <td class="pf-mono"><?= pf_e((string) $order['order_no']) ?>
          <?php if (!empty($order['referral_code'])): ?><div class="pf-faint">ref: <?= pf_e((string) $order['referral_code']) ?></div><?php endif; ?>
        </td>
        <td><?= pf_e((string) ($order['email'] ?? '')) ?></td>
        <td><?= pf_e((string) ($order['product_name'] ?? '')) ?></td>
        <td><?= pf_e(Money::yuan((int) $order['amount_cents'])) ?></td>
        <td class="pf-faint"><?= (int) ($order['discount_cents'] ?? 0) > 0 ? pf_e('- ' . Money::yuan((int) $order['discount_cents']) . ' (' . (string) $order['coupon_code'] . ')') : '—' ?></td>
        <td><?= pf_e((string) ($order['channel'] ?? '')) ?></td>
        <td><span class="pf-pill <?= OrderStateMachine::isPaidLike($status) ? (($status === 'refunded') ? 'danger' : 'ok') : 'neutral' ?>"><?= pf_e(OrderStateMachine::label($status)) ?></span></td>
        <td class="pf-faint"><?= pf_e(date('m-d H:i', strtotime((string) $order['created_at']))) ?></td>
        <td style="white-space:nowrap">
          <?php if (!OrderStateMachine::isPaidLike($status)): ?>
            <form method="post" action="<?= pf_url('/admin/orders/' . (string) $order['id'] . '/confirm') ?>" style="display:inline">
              <button class="pf-btn sm" type="submit">确认到账</button>
            </form>
          <?php endif; ?>
          <?php if (OrderStateMachine::can($status, OrderStateMachine::FAILED)): ?>
            <form method="post" action="<?= pf_url('/admin/orders/' . (string) $order['id'] . '/fail') ?>" style="display:inline" onsubmit="return confirm('标记为支付失败？')">
              <button class="pf-btn ghost sm" type="submit">标记失败</button>
            </form>
          <?php endif; ?>
          <?php if (OrderStateMachine::can($status, OrderStateMachine::REFUNDED)): ?>
            <form method="post" action="<?= pf_url('/admin/orders/' . (string) $order['id'] . '/refund') ?>" style="display:inline" onsubmit="return confirm('确认退款？')">
              <button class="pf-btn ghost sm" type="submit">退款</button>
            </form>
          <?php endif; ?>
          <a class="pf-btn ghost sm" href="<?= pf_url('/d/' . (string) $order['token']) ?>" target="_blank" rel="noopener">交付页</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($orders === []): ?><tr><td colspan="9" class="pf-faint">暂无订单</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <div class="pf-row-between" style="margin-top:16px">
    <span class="pf-faint">第 <?= (int) $page ?> / <?= (int) $pages ?> 页</span>
    <div style="display:flex;gap:8px">
      <?php if ($page > 1): ?><a class="pf-btn ghost sm" href="<?= pf_url('/admin/orders?page=' . ($page - 1) . ($q !== '' ? '&q=' . rawurlencode($q) : '')) ?>">上一页</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="pf-btn ghost sm" href="<?= pf_url('/admin/orders?page=' . ($page + 1) . ($q !== '' ? '&q=' . rawurlencode($q) : '')) ?>">下一页</a><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
