<?php
$active = 'products';
require __DIR__ . '/../partials/admin-head.php';
$isEdit = $product !== null;
$action = $isEdit
    ? pf_url('/admin/products/' . (string) $product['id'] . '/edit')
    : pf_url('/admin/products/new');
$ent = $product['entitlement'] ?? [];
$itemLines = '';
foreach (($ent['items'] ?? []) as $item) {
    $itemLines .= (($item['title'] ?? '') === ($item['url'] ?? '') ? $item['url'] : ($item['title'] . '|' . $item['url'])) . "\n";
}
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700"><?= $isEdit ? '编辑商品' : '新建商品' ?></h1>
</section>

<form class="pf-card" method="post" action="<?= $action ?>" style="display:grid;gap:18px">
  <div class="pf-grid cols-2">
    <div class="pf-field">
      <label for="name">名称</label>
      <input class="pf-input" id="name" name="name" required value="<?= pf_e((string) ($product['name'] ?? '')) ?>">
    </div>
    <div class="pf-field">
      <label for="slug">Slug（可留空自动生成）</label>
      <input class="pf-input" id="slug" name="slug" value="<?= pf_e((string) ($product['slug'] ?? '')) ?>">
    </div>
  </div>

  <div class="pf-field">
    <label for="description">描述</label>
    <textarea class="pf-textarea" id="description" name="description" style="font-family:inherit;font-size:14px"><?= pf_e((string) ($product['description'] ?? '')) ?></textarea>
  </div>

  <div class="pf-grid cols-2">
    <div class="pf-field">
      <label for="type">定价类型</label>
      <select class="pf-select" id="type" name="type">
        <option value="one_time" <?= ($product['type'] ?? 'one_time') === 'one_time' ? 'selected' : '' ?>>一次性买断</option>
        <option value="subscription" <?= ($product['type'] ?? '') === 'subscription' ? 'selected' : '' ?>>定期订阅</option>
      </select>
    </div>
    <div class="pf-field">
      <label for="amount">价格（元）</label>
      <input class="pf-input" id="amount" name="amount" type="number" min="0" step="0.01" required
             value="<?= pf_e(number_format(((int) ($product['amount_cents'] ?? 0)) / 100, 2, '.', '')) ?>">
    </div>
  </div>

  <div class="pf-grid cols-3">
    <div class="pf-field">
      <label for="interval">订阅周期</label>
      <select class="pf-select" id="interval" name="interval">
        <option value="month" <?= ($product['interval'] ?? 'month') === 'month' ? 'selected' : '' ?>>每月</option>
        <option value="year" <?= ($product['interval'] ?? '') === 'year' ? 'selected' : '' ?>>每年</option>
      </select>
    </div>
    <div class="pf-field">
      <label for="trial_days">试用天数（订阅）</label>
      <input class="pf-input" id="trial_days" name="trial_days" type="number" min="0" value="<?= (int) ($product['trial_days'] ?? 0) ?>">
    </div>
    <div class="pf-field">
      <label for="currency">货币</label>
      <input class="pf-input" id="currency" name="currency" value="<?= pf_e((string) ($product['currency'] ?? 'CNY')) ?>">
    </div>
  </div>

  <div class="pf-grid cols-3">
    <div class="pf-field">
      <label for="entitlement_kind">权益类型</label>
      <select class="pf-select" id="entitlement_kind" name="entitlement_kind">
        <option value="content" <?= ($ent['kind'] ?? 'content') === 'content' ? 'selected' : '' ?>>内容交付（URL 白名单）</option>
        <option value="membership" <?= ($ent['kind'] ?? '') === 'membership' ? 'selected' : '' ?>>购买即会员</option>
      </select>
    </div>
    <div class="pf-field">
      <label for="membership_level">会员等级</label>
      <input class="pf-input" id="membership_level" name="membership_level" value="<?= pf_e((string) ($ent['membership_level'] ?? 'member')) ?>">
    </div>
    <div class="pf-field">
      <label for="duration_days">会员时长（天，0=永久）</label>
      <input class="pf-input" id="duration_days" name="duration_days" type="number" min="0" value="<?= (int) ($ent['duration_days'] ?? 0) ?>">
    </div>
  </div>

  <div class="pf-field">
    <label for="items">内容清单（每行一条，格式：标题|URL；只写 URL 亦可）</label>
    <textarea class="pf-textarea" id="items" name="items" placeholder="第一课|https://example.com/lesson-1&#10;https://example.com/download.zip"><?= pf_e(trim($itemLines)) ?></textarea>
  </div>

  <div class="pf-grid cols-2">
    <div class="pf-field">
      <label for="sort">排序（越小越靠前）</label>
      <input class="pf-input" id="sort" name="sort" type="number" value="<?= (int) ($product['sort'] ?? 100) ?>">
    </div>
    <div class="pf-field" style="justify-content:flex-end">
      <label class="pf-check"><input type="checkbox" name="active" value="1" <?= ($product['active'] ?? true) ? 'checked' : '' ?>> 上架在售</label>
    </div>
  </div>

  <div class="pf-grid cols-2">
    <div class="pf-field" style="justify-content:flex-end">
      <label class="pf-check"><input type="checkbox" name="license_enabled" value="1" <?= ($product['license_enabled'] ?? false) ? 'checked' : '' ?>> 交付时签发 License 密钥</label>
    </div>
    <div class="pf-field">
      <label for="license_prefix">License 前缀</label>
      <input class="pf-input" id="license_prefix" name="license_prefix" value="<?= pf_e((string) ($product['license_prefix'] ?? 'PF')) ?>">
    </div>
  </div>

  <div class="pf-grid cols-2">
    <div class="pf-field" style="justify-content:flex-end">
      <label class="pf-check"><input type="checkbox" name="card_enabled" value="1" <?= ($product['card_enabled'] ?? false) ? 'checked' : '' ?>> 发卡交付（从卡密库存自动发一张）</label>
    </div>
  </div>

  <div class="pf-row-between">
    <a class="pf-btn ghost" href="<?= pf_url('/admin/products') ?>">取消</a>
    <button class="pf-btn" type="submit">保存商品</button>
  </div>
</form>

<?php if ($isEdit): ?>
<section class="pf-card" style="margin-top:20px">
  <div class="pf-row-between" style="margin-bottom:10px">
    <h3>文件资产</h3>
    <div style="display:flex;gap:8px">
      <a class="pf-btn ghost sm" href="<?= pf_url('/admin/products/' . (string) $product['id'] . '/assets') ?>">管理文件</a>
      <a class="pf-btn ghost sm" href="<?= pf_url('/admin/products/' . (string) $product['id'] . '/cards') ?>">发卡库存</a>
    </div>
  </div>
  <p class="pf-muted">上传交付文件（源码/PDF 等），客户支付后通过签名链接下载；发卡则从卡密库存自动发放。</p>
</section>
<?php endif; ?>

<?php if ($isEdit): ?>
<section class="pf-card" style="margin-top:20px">
  <div class="pf-row-between" style="margin-bottom:10px">
    <h3>一行嵌入</h3>
    <a class="pf-btn ghost sm" href="<?= pf_url('/checkout?product=' . (string) $product['id']) ?>" target="_blank" rel="noopener">预览收银台</a>
  </div>
  <p class="pf-muted" style="margin-bottom:10px">把下面这段丢进任何页面即可收款：</p>
  <pre class="pf-code">&lt;script src="<?= pf_e(rtrim((string) $baseUrl, '/')) ?>/checkout.js"
        data-product="<?= pf_e((string) $product['id']) ?>"
        data-label="立即购买"&gt;&lt;/script&gt;</pre>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
