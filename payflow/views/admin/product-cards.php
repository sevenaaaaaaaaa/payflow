<?php
$active = 'products';
require __DIR__ . '/../partials/admin-head.php';
?>

<section class="page-head">
  <h1 style="font-size:24px;font-weight:700">发卡库存 · <?= pf_e((string) $product['name']) ?></h1>
  <p class="pf-muted" style="margin-top:6px">每笔支付自动发一张可用卡密。需在商品编辑页勾选「发卡交付」。</p>
</section>

<?php if (!empty($flash)): ?><div class="pf-notice ok" style="margin-bottom:16px"><?= pf_e((string) $flash) ?></div><?php endif; ?>

<section class="pf-grid cols-3" style="margin-bottom:18px">
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $stats['available'] ?></span><span class="l">可用</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $stats['issued'] ?></span><span class="l">已发出</span></div></div>
  <div class="pf-card"><div class="pf-stat"><span class="n"><?= (int) $stats['total'] ?></span><span class="l">总数</span></div></div>
</section>

<div class="pf-grid cols-2" style="align-items:start">
  <div class="pf-card">
    <h3 style="margin-bottom:12px">批量导入</h3>
    <form method="post" action="<?= pf_url('/admin/products/' . (string) $product['id'] . '/cards/import') ?>" style="display:grid;gap:14px">
      <div class="pf-field">
        <label for="codes">每行一张卡密</label>
        <textarea class="pf-textarea" id="codes" name="codes" placeholder="CARD-AAAA-1111&#10;CARD-BBBB-2222"></textarea>
      </div>
      <button class="pf-btn" type="submit">导入</button>
    </form>
  </div>

  <div class="pf-card">
    <h3 style="margin-bottom:12px">卡密列表</h3>
    <div class="table-wrap"><table class="pf-table">
      <thead><tr><th>卡密</th><th>状态</th><th>订单</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cards as $card): ?>
        <tr>
          <td class="pf-mono"><?= pf_e((string) $card['code']) ?></td>
          <td><span class="pf-pill <?= ($card['status'] ?? '') === 'available' ? 'ok' : (($card['status'] ?? '') === 'issued' ? 'accent' : 'neutral') ?>"><?= pf_e((string) $card['status']) ?></span></td>
          <td class="pf-faint pf-mono"><?= pf_e((string) ($card['order_id'] ?? '—')) ?></td>
          <td>
            <form method="post" action="<?= pf_url('/admin/products/' . (string) $product['id'] . '/cards/' . (string) $card['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('删除该卡密？')">
              <button class="pf-btn ghost sm" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($cards === []): ?><tr><td colspan="4" class="pf-faint">还没有卡密</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>

<p style="margin-top:16px"><a class="pf-btn ghost" href="<?= pf_url('/admin/products') ?>">返回商品</a></p>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
