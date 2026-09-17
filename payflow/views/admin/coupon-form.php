<?php
$active = 'coupons';
require __DIR__ . '/../partials/admin-head.php';
$isEdit = $coupon !== null;
$action = $isEdit ? pf_url('/admin/coupons/' . (string) $coupon['id'] . '/edit') : pf_url('/admin/coupons/new');
$type = $coupon['type'] ?? 'percent';
$value = $type === 'fixed' ? number_format(((int) ($coupon['value'] ?? 0)) / 100, 2, '.', '') : (string) ($coupon['value'] ?? 10);
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700"><?= $isEdit ? '编辑优惠券' : '新建优惠券' ?></h1>
</section>

<form class="pf-card" method="post" action="<?= $action ?>" style="display:grid;gap:18px">
  <div class="pf-grid cols-2">
    <div class="pf-field">
      <label for="code">优惠码</label>
      <input class="pf-input" id="code" name="code" required value="<?= pf_e((string) ($coupon['code'] ?? '')) ?>" placeholder="WELCOME20">
    </div>
    <div class="pf-field">
      <label for="description">说明</label>
      <input class="pf-input" id="description" name="description" value="<?= pf_e((string) ($coupon['description'] ?? '')) ?>">
    </div>
  </div>

  <div class="pf-grid cols-3">
    <div class="pf-field">
      <label for="type">类型</label>
      <select class="pf-select" id="type" name="type">
        <option value="percent" <?= $type === 'percent' ? 'selected' : '' ?>>折扣（%）</option>
        <option value="fixed" <?= $type === 'fixed' ? 'selected' : '' ?>>满减（元）</option>
      </select>
    </div>
    <div class="pf-field">
      <label for="value">值（% 或 元）</label>
      <input class="pf-input" id="value" name="value" required value="<?= pf_e($value) ?>">
    </div>
    <div class="pf-field">
      <label for="min_amount">最低消费（元，0=不限）</label>
      <input class="pf-input" id="min_amount" name="min_amount" value="<?= pf_e(number_format(((int) ($coupon['min_amount_cents'] ?? 0)) / 100, 2, '.', '')) ?>">
    </div>
  </div>

  <div class="pf-grid cols-3">
    <div class="pf-field">
      <label for="max_redemptions">总次数上限（0=不限）</label>
      <input class="pf-input" id="max_redemptions" name="max_redemptions" type="number" min="0" value="<?= (int) ($coupon['max_redemptions'] ?? 0) ?>">
    </div>
    <div class="pf-field">
      <label for="per_customer_limit">每人限用（0=不限）</label>
      <input class="pf-input" id="per_customer_limit" name="per_customer_limit" type="number" min="0" value="<?= (int) ($coupon['per_customer_limit'] ?? 0) ?>">
    </div>
    <div class="pf-field" style="justify-content:flex-end">
      <label class="pf-check"><input type="checkbox" name="active" value="1" <?= ($coupon['active'] ?? true) ? 'checked' : '' ?>> 启用</label>
    </div>
  </div>

  <div class="pf-grid cols-2">
    <div class="pf-field">
      <label for="starts_at">开始时间（可空）</label>
      <input class="pf-input" id="starts_at" name="starts_at" type="datetime-local" value="<?= pf_e(!empty($coupon['starts_at']) ? date('Y-m-d\TH:i', strtotime((string) $coupon['starts_at'])) : '') ?>">
    </div>
    <div class="pf-field">
      <label for="expires_at">结束时间（可空）</label>
      <input class="pf-input" id="expires_at" name="expires_at" type="datetime-local" value="<?= pf_e(!empty($coupon['expires_at']) ? date('Y-m-d\TH:i', strtotime((string) $coupon['expires_at'])) : '') ?>">
    </div>
  </div>

  <div class="pf-row-between">
    <a class="pf-btn ghost" href="<?= pf_url('/admin/coupons') ?>">取消</a>
    <button class="pf-btn" type="submit">保存优惠券</button>
  </div>
</form>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
