<?php require __DIR__ . '/partials/head.php'; ?>

<section style="padding:34px 0 10px">
  <span class="pf-eyebrow">安全结账</span>
  <h1 style="font-size:26px;font-weight:700;margin-top:14px"><?= pf_e((string) $product['name']) ?></h1>
  <p class="pf-muted" style="margin-top:8px"><?= pf_e((string) ($product['description'] ?? '')) ?></p>
</section>

<div class="pf-grid pf-checkout-grid">
  <div class="pf-card">
    <form id="pf-form" autocomplete="on">
      <input type="hidden" name="product" value="<?= pf_e((string) $product['id']) ?>">
      <div class="pf-field">
        <label for="pf-email">邮箱（接收交付内容）</label>
        <input class="pf-input" type="email" id="pf-email" name="email" placeholder="you@example.com" required>
      </div>
      <div class="pf-field">
        <label for="pf-name">称呼（可选）</label>
        <input class="pf-input" type="text" id="pf-name" name="name" placeholder="你的名字">
      </div>
      <?php if ($channels !== []): ?>
      <div class="pf-field">
        <label for="pf-channel">支付方式</label>
        <select class="pf-select" id="pf-channel" name="channel">
          <?php foreach ($channels as $channel): ?>
            <option value="<?= pf_e($channel['id']) ?>"><?= pf_e($channel['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
        <div class="pf-notice danger">当前没有可用的支付通道，请在后台配置。</div>
      <?php endif; ?>
      <div class="pf-field">
        <label for="pf-coupon">优惠券（可选）</label>
        <div style="display:flex;gap:8px">
          <input class="pf-input" type="text" id="pf-coupon" name="coupon" placeholder="优惠码" autocomplete="off">
          <button class="pf-btn ghost" type="button" id="pf-coupon-apply" style="flex:0 0 auto">应用</button>
        </div>
        <div id="pf-coupon-msg" class="pf-faint"></div>
      </div>
      <div id="pf-error" class="pf-notice danger" style="display:none;margin-bottom:14px"></div>
      <button class="pf-btn block" type="submit" id="pf-submit">支付 <?= pf_e($priceLabel) ?></button>
      <p class="pf-faint" style="margin-top:12px">提交即创建订单并跳转支付；支付成功自动交付并发送邮件。</p>
    </form>
  </div>

  <aside class="pf-card">
    <div class="pf-row-between">
      <span class="pf-muted">应付金额</span>
      <strong style="font-size:22px" id="pf-payable"><?= pf_e($priceLabel) ?></strong>
    </div>
    <div id="pf-discount-row" class="pf-row-between" style="display:none;margin-top:8px">
      <span class="pf-muted">优惠</span>
      <span style="color:var(--ok)" id="pf-discount-label">-</span>
    </div>
    <hr style="border:none;border-top:1px solid var(--border-soft);margin:16px 0">
    <ol class="pf-steps">
      <li>填写邮箱，创建订单</li>
      <li>扫码 / 跳转完成支付</li>
      <li>内容与会员权益自动发放</li>
    </ol>
    <div style="margin-top:16px" class="pf-faint">
      类型：<?= ($product['type'] ?? '') === 'subscription' ? '定期订阅' : '一次性买断' ?>
      <?php if (($product['type'] ?? '') === 'subscription'): ?>
        · 每 <?= ($product['interval'] ?? 'month') === 'year' ? '年' : '月' ?>自动续费
      <?php endif; ?>
    </div>
  </aside>
</div>

<script>
(function () {
  var form = document.getElementById('pf-form');
  var submit = document.getElementById('pf-submit');
  var errorBox = document.getElementById('pf-error');
  var embed = <?= $embed ? 'true' : 'false' ?>;
  var BASE = <?= json_encode(pf_base_path()) ?>;
  var productId = <?= json_encode((string) $product['id']) ?>;
  var basePrice = <?= json_encode($priceLabel) ?>;
  var appliedCoupon = '';

  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.style.display = 'block';
  }

  var couponInput = document.getElementById('pf-coupon');
  var couponMsg = document.getElementById('pf-coupon-msg');
  var discountRow = document.getElementById('pf-discount-row');
  var discountLabel = document.getElementById('pf-discount-label');
  var payable = document.getElementById('pf-payable');
  var applyBtn = document.getElementById('pf-coupon-apply');

  function applyCoupon() {
    var code = couponInput.value.trim();
    if (!code) { return; }
    couponMsg.textContent = '校验中…';
    fetch(BASE + '/api/coupon/validate?product=' + encodeURIComponent(productId)
      + '&code=' + encodeURIComponent(code)
      + '&email=' + encodeURIComponent(form.email.value))
      .then(function (d) {
        if (!d.ok) {
          couponMsg.textContent = d.reason || '优惠券不可用';
          couponMsg.style.color = 'var(--danger)';
          appliedCoupon = '';
          discountRow.style.display = 'none';
          payable.textContent = basePrice;
          return;
        }
        appliedCoupon = code;
        couponMsg.textContent = '已应用';
        couponMsg.style.color = 'var(--ok)';
        discountLabel.textContent = '-' + d.discount;
        discountRow.style.display = 'flex';
        payable.textContent = d.amount;
      })
      .catch(function () { couponMsg.textContent = '校验失败，请重试'; couponMsg.style.color = 'var(--danger)'; });
  }
  applyBtn.addEventListener('click', applyCoupon);

  if (embed) {
    var close = document.createElement('button');
    close.textContent = '关闭';
    close.className = 'pf-btn ghost sm';
    close.style.float = 'right';
    close.addEventListener('click', function () {
      parent.postMessage({ type: 'payflow:close' }, '*');
    });
    document.querySelector('h1').appendChild(close);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errorBox.style.display = 'none';
    submit.disabled = true;
    submit.textContent = '正在创建订单…';

    var payload = {
      product: form.product.value,
      email: form.email.value,
      name: form.name.value,
      channel: form.channel ? form.channel.value : '',
      coupon: appliedCoupon || couponInput.value.trim()
    };

    fetch(BASE + '/api/checkout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.d.ok) {
          showError(res.d.error || '创建订单失败');
          submit.disabled = false;
          submit.textContent = '重试';
          return;
        }
        window.location.href = res.d.pay_url;
      })
      .catch(function () {
        showError('网络异常，请重试');
        submit.disabled = false;
        submit.textContent = '重试';
      });
  });
})();
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
