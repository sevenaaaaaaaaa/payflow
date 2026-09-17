<?php require __DIR__ . '/partials/head.php'; use PayFlow\Support\Money; ?>

<section style="padding:34px 0 10px">
  <span class="pf-eyebrow">支付链接</span>
  <h1 style="font-size:26px;font-weight:700;margin-top:14px"><?= pf_e((string) $link['title']) ?></h1>
  <?php if (!empty($link['description'])): ?><p class="pf-muted" style="margin-top:8px"><?= pf_e((string) $link['description']) ?></p><?php endif; ?>
</section>

<div class="pf-grid pf-checkout-grid">
  <div class="pf-card">
    <form id="pf-form" autocomplete="on">
      <input type="hidden" name="token" value="<?= pf_e((string) $link['token']) ?>">
      <div class="pf-field">
        <label for="pf-email">邮箱（接收交付/收据）</label>
        <input class="pf-input" type="email" id="pf-email" name="email" placeholder="you@example.com" required>
      </div>
      <div class="pf-field">
        <label for="pf-name">称呼（可选）</label>
        <input class="pf-input" type="text" id="pf-name" name="name">
      </div>
      <?php if ($channels !== []): ?>
        <div class="pf-field">
          <label for="pf-channel">支付方式</label>
          <select class="pf-select" id="pf-channel" name="channel">
            <?php foreach ($channels as $channel): ?><option value="<?= pf_e($channel['id']) ?>"><?= pf_e($channel['label']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <div class="pf-notice danger">当前没有可用的支付通道。</div>
      <?php endif; ?>
      <?php if ($allowCoupon): ?>
        <div class="pf-field">
          <label for="pf-coupon">优惠码（可选）</label>
          <input class="pf-input" type="text" id="pf-coupon" name="coupon" autocomplete="off">
        </div>
      <?php endif; ?>
      <div id="pf-error" class="pf-notice danger" style="display:none;margin-bottom:14px"></div>
      <button class="pf-btn block" type="submit" id="pf-submit">支付 <?= pf_e(Money::yuan((int) $link['amount_cents'])) ?></button>
    </form>
  </div>

  <aside class="pf-card">
    <div class="pf-row-between"><span class="pf-muted">应付金额</span><strong style="font-size:22px"><?= pf_e(Money::yuan((int) $link['amount_cents'])) ?></strong></div>
    <hr style="border:none;border-top:1px solid var(--border-soft);margin:16px 0">
    <ol class="pf-steps">
      <li>填写邮箱，创建订单</li>
      <li>扫码 / 跳转完成支付</li>
      <li>自动交付并发送收据</li>
    </ol>
  </aside>
</div>

<script>
(function () {
  var form = document.getElementById('pf-form');
  var submit = document.getElementById('pf-submit');
  var errorBox = document.getElementById('pf-error');
  var BASE = <?= json_encode(pf_base_path()) ?>;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errorBox.style.display = 'none';
    submit.disabled = true; submit.textContent = '正在创建订单…';
    var payload = {
      token: form.token.value, email: form.email.value, name: form.name.value,
      channel: form.channel ? form.channel.value : '',
      coupon: form.coupon ? form.coupon.value : ''
    };
    fetch(BASE + '/api/link/checkout', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.d.ok) { errorBox.textContent = res.d.error || '创建订单失败'; errorBox.style.display = 'block'; submit.disabled = false; submit.textContent = '重试'; return; }
        window.location.href = res.d.pay_url;
      })
      .catch(function () { errorBox.textContent = '网络异常，请重试'; errorBox.style.display = 'block'; submit.disabled = false; submit.textContent = '重试'; });
  });
})();
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
