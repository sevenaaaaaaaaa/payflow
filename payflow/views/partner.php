<?php
require __DIR__ . '/partials/head.php';
use PayFlow\Support\Money;
$r = $referral;
$s = $summary;
?>

<section style="padding:34px 0 12px">
  <span class="pf-eyebrow">推荐人中心</span>
  <h1 style="font-size:26px;font-weight:700;margin-top:14px"><?= pf_e((string) $r['email']) ?></h1>
  <p class="pf-muted" style="margin-top:6px">推荐码 <strong class="pf-mono"><?= pf_e((string) $r['code']) ?></strong> · 层级 <?= pf_e((string) $r['level']) ?> · 成交 <?= (int) ($r['orders'] ?? 0) ?> 单</p>
</section>

<section class="pf-grid cols-3" style="margin-bottom:20px">
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $s['withdrawable'])) ?></span><span class="l">可提现</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $s['pending'])) ?></span><span class="l">待解冻</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(Money::yuan((int) $s['paid'])) ?></span><span class="l">已打款</span></div></div>
</section>

<div class="pf-card" style="margin-bottom:20px">
  <h3 style="margin-bottom:10px">你的推广链接</h3>
  <div class="pf-code"><?= pf_e((string) $link) ?></div>
  <p class="pf-faint" style="margin-top:8px">把它分享给朋友；他们通过该链接下单并支付后，你会获得佣金。</p>
</div>

<div class="pf-grid pf-checkout-grid">
  <div class="pf-card">
    <h3 style="margin-bottom:12px">申请提现</h3>
    <?php if (!empty($ok)): ?><div class="pf-notice ok" style="margin-bottom:12px">提现申请已提交，等待审核。</div><?php endif; ?>
    <?php if (!empty($flash)): ?><div class="pf-notice danger" style="margin-bottom:12px"><?= pf_e((string) $flash) ?></div><?php endif; ?>
    <form method="post" action="<?= pf_url('/partner?token=' . rawurlencode((string) $token)) ?>">
      <div class="pf-field">
        <label for="amount">金额（元）· 最低 <?= pf_e(Money::yuan((int) $min)) ?></label>
        <input class="pf-input" id="amount" name="amount" required value="<?= pf_e(number_format((int) $s['withdrawable'] / 100, 2, '.', '')) ?>">
      </div>
      <div class="pf-field">
        <label for="method">方式</label>
        <select class="pf-select" id="method" name="method">
          <?php foreach ($methods as $m): ?><option value="<?= pf_e((string) $m) ?>"><?= pf_e((string) $m) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="pf-field">
        <label for="account">收款账号</label>
        <input class="pf-input" id="account" name="account" placeholder="支付宝 / 微信 / 银行卡号">
      </div>
      <div class="pf-field">
        <label for="note">备注（可选）</label>
        <input class="pf-input" id="note" name="note">
      </div>
      <button class="pf-btn block" type="submit">提交提现申请</button>
    </form>
  </div>

  <div class="pf-card">
    <h3 style="margin-bottom:12px">佣金与提现记录</h3>
    <?php if ($payouts !== []): ?>
      <table class="pf-table" style="margin-bottom:12px">
        <thead><tr><th>金额</th><th>状态</th><th>时间</th></tr></thead>
        <tbody>
        <?php foreach ($payouts as $p): ?>
          <tr><td><?= pf_e(Money::yuan((int) $p['amount_cents'])) ?></td><td><?= pf_e((string) $p['status']) ?></td><td class="pf-faint"><?= pf_e(substr((string) $p['created_at'], 0, 10)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <div class="pf-list">
      <?php foreach ($commissions as $c): ?>
        <div class="pf-row-between" style="font-size:13px;border-bottom:1px solid var(--border-soft);padding-bottom:8px">
          <span><?= pf_e((string) $c['order_no']) ?> · <?= pf_e(Money::yuan((int) $c['amount_cents'])) ?></span>
          <span class="pf-pill <?= $c['status'] === 'paid' ? 'ok' : ($c['status'] === 'available' ? 'accent' : 'neutral') ?>"><?= pf_e((string) $c['status']) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if ($commissions === []): ?><span class="pf-faint">还没有佣金记录</span><?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/foot.php'; ?>
