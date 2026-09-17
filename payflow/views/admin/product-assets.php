<?php
$active = 'products';
require __DIR__ . '/../partials/admin-head.php';
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">文件资产 · <?= pf_e((string) $product['name']) ?></h1>
  <p class="pf-muted" style="margin-top:6px">上传的文件仅在客户支付后通过签名链接下载，不可直连。</p>
</section>

<?php if (!empty($flash)): ?><div class="pf-notice <?= ($flash === '上传成功') ? 'ok' : 'danger' ?>" style="margin-bottom:16px"><?= pf_e((string) $flash) ?></div><?php endif; ?>

<div class="pf-grid cols-2" style="align-items:start">
  <div class="pf-card">
    <h3 style="margin-bottom:12px">上传文件</h3>
    <form method="post" action="<?= pf_url('/admin/products/' . (string) $product['id'] . '/assets') ?>" enctype="multipart/form-data" style="display:grid;gap:14px">
      <div class="pf-field">
        <label for="title">展示名称（可选）</label>
        <input class="pf-input" id="title" name="title" placeholder="例如：完整源码包">
      </div>
      <div class="pf-field">
        <label for="file">文件</label>
        <input class="pf-input" id="file" name="file" type="file" required>
      </div>
      <div class="pf-field">
        <label for="download_limit">下载次数上限（0=不限）</label>
        <input class="pf-input" id="download_limit" name="download_limit" type="number" min="0" value="0">
      </div>
      <button class="pf-btn" type="submit">上传</button>
    </form>
  </div>

  <div class="pf-card">
    <h3 style="margin-bottom:12px">已上传（<?= count($assets) ?>）</h3>
    <table class="pf-table">
      <thead><tr><th>名称</th><th>大小</th><th>限次</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($assets as $asset): ?>
        <tr>
          <td><?= pf_e((string) $asset['title']) ?><div class="pf-faint pf-mono"><?= pf_e((string) $asset['original_name']) ?></div></td>
          <td><?= number_format(((int) $asset['size']) / 1024, 0) ?> KB</td>
          <td><?= (int) ($asset['download_limit'] ?? 0) === 0 ? '∞' : (int) $asset['download_limit'] ?></td>
          <td>
            <form method="post" action="<?= pf_url('/admin/products/' . (string) $product['id'] . '/assets/' . (string) $asset['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('删除该文件？')">
              <button class="pf-btn ghost sm" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($assets === []): ?><tr><td colspan="4" class="pf-faint">还没有文件</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<p style="margin-top:16px"><a class="pf-btn ghost" href="<?= pf_url('/admin/products') ?>">返回商品</a></p>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
