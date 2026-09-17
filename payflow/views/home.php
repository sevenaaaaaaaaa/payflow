<?php require __DIR__ . '/partials/head.php'; ?>

<section class="pf-hero">
  <span class="pf-eyebrow">演示店铺</span>
  <h1>一行嵌入，<span class="si">任何页面</span>都能收款</h1>
  <p>收款 + 订阅 + 推荐裂变 + 佣金结算。本页是 PayFlow 的演示店铺，用于直观查看商品与结账效果；线上正式入口 <code>/payflow</code> 是后台。</p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:24px">
    <a class="pf-btn" href="#products">浏览商品</a>
    <a class="pf-btn ghost" href="<?= pf_url('/admin') ?>">进入后台</a>
  </div>
</section>

<section class="pf-grid cols-3" style="margin-bottom:36px">
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $stats['paid'] ?></span><span class="l">已支付订单</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= pf_e(\PayFlow\Support\Money::yuan((int) $stats['revenue_cents'])) ?></span><span class="l">累计 GMV</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= count($channels) ?></span><span class="l">启用支付通道</span></div></div>
</section>

<section id="products" style="margin-bottom:40px">
  <div class="pf-row-between" style="margin-bottom:18px">
    <h2 style="font-size:22px;font-weight:700">在售商品</h2>
    <span class="pf-faint">一次性 + 订阅两种定价</span>
  </div>

  <?php if ($products === []): ?>
    <div class="pf-notice warn">还没有商品。到 <a href="<?= pf_url('/admin/products/new') ?>" style="color:var(--accent)">后台新建商品</a> 后即可嵌入结账。</div>
  <?php else: ?>
    <div class="pf-grid cols-3">
      <?php foreach ($products as $product): ?>
        <article class="pf-card hoverable">
          <div class="pf-row-between">
            <h3><?= pf_e((string) $product['name']) ?></h3>
            <span class="pf-pill <?= ($product['type'] ?? '') === 'subscription' ? 'accent' : 'neutral' ?>">
              <?= ($product['type'] ?? '') === 'subscription' ? '订阅' : '一次性' ?>
            </span>
          </div>
          <p class="desc"><?= pf_e((string) ($product['description'] ?? '')) ?></p>
          <div class="pf-price"><?= pf_e(\PayFlow\Support\Money::yuan((int) $product['amount_cents'])) ?>
            <?php if (($product['type'] ?? '') === 'subscription'): ?><small>/ <?= ($product['interval'] ?? 'month') === 'year' ? '年' : '月' ?></small><?php endif; ?>
          </div>
          <div style="margin-top:16px;display:flex;gap:10px">
            <a class="pf-btn block" href="<?= pf_url('/checkout?product=' . (string) $product['id']) ?>">购买</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section id="embed" style="margin-bottom:48px">
  <h2 style="font-size:22px;font-weight:700;margin-bottom:14px">三分钟接入</h2>
  <p class="pf-muted" style="margin-bottom:14px">把下面一行丢进任何页面，就能收款：</p>
  <pre class="pf-code">&lt;script src="<?= pf_e(rtrim((string) $baseUrl, '/')) ?>/checkout.js"
        data-product="<?= pf_e((string) ($products[0]['id'] ?? 'prod_xxx')) ?>"
        data-label="立即购买"&gt;&lt;/script&gt;</pre>
  <div class="pf-grid cols-3" style="margin-top:18px">
    <div class="pf-card"><h3>托管收银台</h3><p class="desc">弹窗 / 跳转两种形态，原生适配暗色模式。</p></div>
    <div class="pf-card"><h3>订单状态机</h3><p class="desc">created → paid → delivered → refunded，全程可审计。</p></div>
    <div class="pf-card"><h3>购买即会员</h3><p class="desc">权益挂内容 URL 白名单，或直接发放会员等级。</p></div>
  </div>
</section>

<?php require __DIR__ . '/partials/foot.php'; ?>
