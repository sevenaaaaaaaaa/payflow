<?php
$active = 'payment-links';
require __DIR__ . '/../partials/admin-head.php';
use PayFlow\Support\Money;
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>

<section class="page-head">
  <div>
    <h1>临时支付链接</h1>
    <p class="sub">共 <?= (int) $total ?> 条 · 任意金额一次性收款；可绑定商品（带交付）或纯收款。</p>
  </div>
</section>

<div class="pf-grid cols-2" style="align-items:start">
  <div class="pf-card">
    <h3 class="card-title">新建链接</h3>
    <form method="post" action="<?= pf_url('/admin/payment-links/new') ?>" style="display:grid;gap:14px">
      <div class="pf-field"><label for="title">标题</label><input class="pf-input" id="title" name="title" required placeholder="例如：设计咨询费"></div>
      <div class="pf-field"><label for="description">说明（可选）</label><input class="pf-input" id="description" name="description"></div>
      <div class="pf-grid cols-2">
        <div class="pf-field"><label for="amount">金额（元）</label><input class="pf-input" id="amount" name="amount" required placeholder="199.00"></div>
        <div class="pf-field"><label for="expiry_days">有效天数</label><input class="pf-input" id="expiry_days" name="expiry_days" type="number" min="1" value="7"></div>
      </div>
      <div class="pf-field">
        <label for="product_id">绑定商品（可选，交付其权益）</label>
        <select class="pf-select" id="product_id" name="product_id">
          <option value="">— 纯收款（无交付）—</option>
          <?php foreach ($products as $p): ?><option value="<?= pf_e((string) $p['id']) ?>"><?= pf_e((string) $p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="pf-grid cols-2">
        <div class="pf-field"><label for="max_uses">可用次数（0=不限）</label><input class="pf-input" id="max_uses" name="max_uses" type="number" min="0" value="0"></div>
        <div class="pf-field" style="justify-content:flex-end"><label class="pf-check"><input type="checkbox" name="allow_coupon" value="1"> 允许优惠码</label></div>
      </div>
      <button class="pf-btn" type="submit">创建链接</button>
    </form>
  </div>

  <div class="pf-card">
    <h3 class="card-title">已创建</h3>
    <div class="table-wrap">
      <table class="pf-table">
        <thead><tr><th>标题</th><th class="num">金额</th><th class="num">用量</th><th>状态</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $l = $row['link']; ?>
          <tr>
            <td><?= pf_e((string) $l['title']) ?><div class="pf-faint"><?= pf_e((string) ($l['expires_at'] ?? '—')) ?></div></td>
            <td class="num"><?= pf_e(Money::yuan((int) $l['amount_cents'])) ?></td>
            <td class="num"><?= (int) ($l['used'] ?? 0) ?> / <?= (int) ($l['max_uses'] ?? 0) === 0 ? '∞' : (int) $l['max_uses'] ?></td>
            <td><span class="pf-pill <?= ($l['active'] ?? true) ? 'ok' : 'neutral' ?>"><?= ($l['active'] ?? true) ? '启用' : '停用' ?></span></td>
            <td class="nowrap">
              <a class="pf-btn ghost sm" href="<?= pf_e((string) $row['url']) ?>" target="_blank" rel="noopener">打开</a>
              <form method="post" action="<?= pf_url('/admin/payment-links/' . (string) $l['id'] . '/toggle') ?>" style="display:inline">
                <button class="pf-btn ghost sm" type="submit"><?= ($l['active'] ?? true) ? '停用' : '启用' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="5" class="empty">还没有支付链接</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= pf_pager($page, $pages, '/admin/payment-links') ?>
  </div>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
