<?php
/** @var array $order @var string $token @var array $intent @var string $status @var string $qrEndpoint */
$paidLike = in_array($status, ['paid', 'delivered'], true);
?>
<?php require __DIR__ . '/partials/head.php'; ?>

<section style="padding:30px 0 8px" class="pf-center">
  <span class="pf-eyebrow">订单 <?= pf_e((string) $order['order_no']) ?></span>
  <h1 style="font-size:24px;font-weight:700;margin-top:14px"><?= pf_e((string) $order['product_name']) ?></h1>
  <p class="pf-muted" style="margin-top:6px">应付 <strong style="color:var(--fg)"><?= pf_e((string) $order['amount']) ?></strong></p>
</section>

<div class="pf-card" id="pf-pay-card">
  <div id="pf-status" class="pf-row-between" style="margin-bottom:14px">
    <span class="pf-pill <?= $paidLike ? 'ok' : 'warn' ?>" id="pf-status-pill"><?= pf_e((string) $order['status_label']) ?></span>
    <span class="pf-faint" id="pf-poll-hint"><?= $paidLike ? '' : '正在等待支付结果…' ?></span>
  </div>

  <?php if ($paidLike): ?>
    <div class="pf-notice ok">支付已完成。内容与权益已发放，可前往交付页查看。</div>
  <?php elseif (($intent['mode'] ?? '') === 'qrcode'): ?>
    <div class="pf-qr">
      <?php if (($intent['content'] ?? '') !== '' && $qrEndpoint !== ''): ?>
        <div class="box">
          <img id="pf-qr-img" alt="支付二维码"
               src="<?= pf_e($qrEndpoint . rawurlencode((string) $intent['content'])) ?>">
        </div>
      <?php endif; ?>
      <p class="pf-muted"><?= pf_e((string) ($intent['instructions'] ?? '请扫码完成支付')) ?></p>
      <div class="code" id="pf-qr-code"><?= pf_e((string) ($intent['content'] ?? '')) ?></div>
      <button class="pf-btn ghost sm" type="button" id="pf-copy">复制支付串</button>
    </div>
  <?php elseif (($intent['mode'] ?? '') === 'redirect' && ($intent['url'] ?? '') !== ''): ?>
    <a class="pf-btn block" href="<?= pf_e((string) $intent['url']) ?>">前往支付</a>
  <?php else: ?>
    <div class="pf-notice warn" style="white-space:pre-line"><?= pf_e((string) ($intent['instructions'] ?? '请按管理员指引完成支付')) ?></div>
  <?php endif; ?>

  <div id="pf-done" style="display:none;margin-top:18px"></div>
</div>

<p class="pf-faint pf-center" style="margin-top:16px">
  支付遇到问题？订单号：<span class="pf-mono"><?= pf_e((string) $order['order_no']) ?></span>
</p>

<script>
(function () {
  var token = <?= json_encode($token) ?>;
  var embed = <?= ($embed ?? false) ? 'true' : 'false' ?>;
  var CURRENT = <?= json_encode($status) ?>;
  var BASE = <?= json_encode(pf_base_path()) ?>;
  var pill = document.getElementById('pf-status-pill');
  var hint = document.getElementById('pf-poll-hint');
  var done = document.getElementById('pf-done');
  var copyBtn = document.getElementById('pf-copy');

  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var text = document.getElementById('pf-qr-code').textContent.trim();
      navigator.clipboard.writeText(text).then(function () { copyBtn.textContent = '已复制'; });
    });
  }

  function render(paid) {
    pill.className = 'pf-pill ok';
    pill.textContent = '已支付';
    if (hint) hint.textContent = '';
    var html = '<div class="pf-notice ok">支付成功，正在为你准备内容…</div>'
      + '<a class="pf-btn block" style="margin-top:12px" href="' + paid.delivery_url + '">查看交付内容</a>';
    done.innerHTML = html;
    done.style.display = 'block';
    if (embed) {
      parent.postMessage({ type: 'payflow:paid', redirect_url: paid.delivery_url, order: paid.order_no }, '*');
    }
  }

  function poll() {
    fetch(BASE + '/api/orders/' + encodeURIComponent(token), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.delivery_url) {
          render(d);
          return;
        }
        if (d && (d.status === 'refunded' || d.status === 'canceled' || d.status === 'failed')) {
          pill.className = 'pf-pill danger';
          pill.textContent = d.status_label;
          if (hint) hint.textContent = '';
          return;
        }
        setTimeout(poll, 3000);
      })
      .catch(function () { setTimeout(poll, 5000); });
  }

  if (CURRENT !== 'delivered' && CURRENT !== 'paid') poll();
  if (CURRENT === 'delivered' || CURRENT === 'paid') {
    var pending = document.getElementById('pf-done');
    pending.innerHTML = '<a class="pf-btn block" href="' + BASE + '/return/' + encodeURIComponent(token) + '">查看交付内容</a>';
    pending.style.display = 'block';
  }
})();
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
