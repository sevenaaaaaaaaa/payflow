<?php
$active = 'vouchers';
require __DIR__ . '/../partials/admin-head.php';
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">兑换券</h1>
  <p class="pf-muted" style="margin-top:6px">凭兑换码免费领取指定商品（赠品/激活码）。公开兑换页：<span class="pf-mono"><?= pf_e(pf_url('/redeem')) ?></span></p>
</section>

<?php if (!empty($flash)): ?><div class="pf-notice ok" style="margin-bottom:16px"><?= pf_e((string) $flash) ?></div><?php endif; ?>

<div class="pf-grid cols-2" style="align-items:start">
  <div class="pf-card">
    <h3 style="margin-bottom:12px">批量生成</h3>
    <form method="post" action="<?= pf_url('/admin/vouchers/generate') ?>" style="display:grid;gap:14px">
      <div class="pf-field">
        <label for="product_id">兑换商品</label>
        <select class="pf-select" id="product_id" name="product_id" required>
          <?php foreach ($products as $p): ?><option value="<?= pf_e((string) $p['id']) ?>"><?= pf_e((string) $p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="pf-grid cols-3">
        <div class="pf-field">
          <label for="count">数量</label>
          <input class="pf-input" id="count" name="count" type="number" min="1" max="500" value="10">
        </div>
        <div class="pf-field">
          <label for="prefix">前缀</label>
          <input class="pf-input" id="prefix" name="prefix" value="GIFT">
        </div>
        <div class="pf-field">
          <label for="max_uses">每码可用</label>
          <input class="pf-input" id="max_uses" name="max_uses" type="number" min="1" value="1">
        </div>
      </div>
      <div class="pf-field">
        <label for="expiry_days">有效天数（0=永久）</label>
        <input class="pf-input" id="expiry_days" name="expiry_days" type="number" min="0" value="0">
      </div>
      <button class="pf-btn" type="submit">生成</button>
    </form>
  </div>

  <div class="pf-card">
    <h3 style="margin-bottom:12px">兑换券列表</h3>
    <table class="pf-table">
      <thead><tr><th>兑换码</th><th>商品</th><th>用量</th><th>状态</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($vouchers as $v): ?>
        <tr>
          <td class="pf-mono"><?= pf_e((string) $v['code']) ?></td>
          <td class="pf-faint"><?= pf_e((string) ($v['product_id'] ?? '')) ?></td>
          <td><?= (int) ($v['used_count'] ?? 0) ?> / <?= (int) ($v['max_uses'] ?? 1) === 0 ? '∞' : (int) $v['max_uses'] ?></td>
          <td><span class="pf-pill <?= ($v['active'] ?? true) ? 'ok' : 'neutral' ?>"><?= ($v['active'] ?? true) ? '启用' : '停用' ?></span></td>
          <td>
            <form method="post" action="<?= pf_url('/admin/vouchers/' . (string) $v['id'] . '/toggle') ?>" style="display:inline">
              <button class="pf-btn ghost sm" type="submit"><?= ($v['active'] ?? true) ? '停用' : '启用' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($vouchers === []): ?><tr><td colspan="5" class="pf-faint">还没有兑换券</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
