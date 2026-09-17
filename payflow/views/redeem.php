<?php require __DIR__ . '/partials/head.php'; ?>

<section style="padding:34px 0 10px" class="pf-center">
  <span class="pf-eyebrow">兑换券</span>
  <h1 style="font-size:26px;font-weight:700;margin-top:14px">输入兑换码领取</h1>
</section>

<div class="pf-card pf-narrow" style="margin:0 auto">
  <?php if (!empty($error)): ?><div class="pf-notice danger" style="margin-bottom:14px"><?= pf_e((string) $error) ?></div><?php endif; ?>
  <form method="post" action="<?= pf_url('/redeem') ?>">
    <div class="pf-field">
      <label for="code">兑换码</label>
      <input class="pf-input" id="code" name="code" required value="<?= pf_e((string) $code) ?>" placeholder="GIFT-XXXXXXXX">
    </div>
    <div class="pf-field">
      <label for="email">邮箱（接收交付内容）</label>
      <input class="pf-input" type="email" id="email" name="email" required placeholder="you@example.com">
    </div>
    <div class="pf-field">
      <label for="name">称呼（可选）</label>
      <input class="pf-input" id="name" name="name">
    </div>
    <button class="pf-btn block" type="submit">立即兑换</button>
  </form>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
