<?php require __DIR__ . '/partials/head.php'; ?>

<section style="padding:34px 0 12px">
  <span class="pf-eyebrow">交付页</span>
  <h1 style="font-size:26px;font-weight:700;margin-top:14px"><?= pf_e((string) $order['product_name']) ?></h1>
  <p class="pf-muted" style="margin-top:6px">订单 <?= pf_e((string) $order['order_no']) ?> · <?= pf_e((string) $order['email']) ?></p>
</section>

<?php if (($entitlement['kind'] ?? '') === 'membership'): ?>
  <div class="pf-notice ok" style="margin-bottom:18px">
    会员权益已激活：等级 <strong><?= pf_e((string) ($entitlement['level'] ?? 'member')) ?></strong>
    <?= !empty($entitlement['expires_at']) ? ' · 有效期至 ' . pf_e(date('Y-m-d', strtotime((string) $entitlement['expires_at']))) : ' · 永久有效' ?>
  </div>
<?php endif; ?>

<?php if (($assets ?? []) !== []): ?>
  <h3 style="font-size:18px;font-weight:700;margin:6px 0 12px">文件下载</h3>
  <div class="pf-list" style="margin-bottom:20px">
    <?php foreach ($assets as $asset): ?>
      <a class="pf-card hoverable" href="<?= pf_e((string) $asset['download_url']) ?>">
        <div class="pf-row-between">
          <strong><?= pf_e((string) $asset['title']) ?></strong>
          <span class="pf-pill accent">下载</span>
        </div>
        <div class="pf-faint" style="margin-top:6px">
          <?= pf_e((string) $asset['original_name']) ?> · <?= number_format(((int) $asset['size']) / 1024, 0) ?> KB
          <?php if ((int) ($asset['download_limit'] ?? 0) > 0): ?> · 限 <?= (int) $asset['download_limit'] ?> 次<?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($cards ?? [])): ?>
  <div class="pf-card" style="margin-bottom:20px">
    <h3 style="margin-bottom:8px">卡密（请妥善保管）</h3>
    <?php foreach ($cards as $card): ?>
      <div class="pf-code" style="margin-top:6px"><?= pf_e((string) $card['code']) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($license)): ?>
  <div class="pf-card" style="margin-bottom:20px">
    <h3 style="margin-bottom:8px">License 密钥</h3>
    <div class="pf-code"><?= pf_e((string) $license['license_key']) ?></div>
    <p class="pf-faint" style="margin-top:8px">此密钥与你的订单绑定，请妥善保管。</p>
  </div>
<?php endif; ?>

<?php if ($items === [] && ($assets ?? []) === []): ?>
  <div class="pf-card">暂无附加内容。你的权益已记录，可直接使用会员身份访问受保护内容。</div>
<?php elseif ($items !== []): ?>
  <div class="pf-list">
    <?php foreach ($items as $item): ?>
      <a class="pf-card hoverable" href="<?= pf_e((string) $item['url']) ?>" target="_blank" rel="noopener">
        <div class="pf-row-between">
          <strong><?= pf_e((string) ($item['title'] ?? $item['url'])) ?></strong>
          <span class="pf-pill accent">打开</span>
        </div>
        <div class="pf-mono" style="margin-top:8px"><?= pf_e((string) $item['url']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<p class="pf-faint" style="margin-top:20px">此交付链接仅供本人使用，请勿转发。</p>

<?php require __DIR__ . '/partials/foot.php'; ?>
