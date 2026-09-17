<?php $active = 'products'; require __DIR__ . '/../partials/admin-head.php'; ?>

<section style="padding:28px 0 16px" class="pf-row-between">
  <div>
    <h1 style="font-size:24px;font-weight:700">商品与价格</h1>
    <p class="pf-muted" style="margin-top:6px">一次性买断 + 定期订阅两种定价。</p>
  </div>
  <a class="pf-btn" href="<?= pf_url('/admin/products/new') ?>">新建商品</a>
</section>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>名称</th><th>slug</th><th>类型</th><th>价格</th><th>权益</th><th>状态</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($products as $product): ?>
      <tr>
        <td><strong><?= pf_e((string) $product['name']) ?></strong></td>
        <td class="pf-mono"><?= pf_e((string) ($product['slug'] ?? '')) ?></td>
        <td><?= ($product['type'] ?? '') === 'subscription' ? '订阅' : '一次性' ?></td>
        <td><?= pf_e(\PayFlow\Support\Money::yuan((int) $product['amount_cents'])) ?><?= ($product['type'] ?? '') === 'subscription' ? ' / ' . (($product['interval'] ?? 'month') === 'year' ? '年' : '月') : '' ?></td>
        <td><?= ($product['entitlement']['kind'] ?? '') === 'membership' ? '会员' : '内容 ' . count($product['entitlement']['items'] ?? []) . ' 项' ?></td>
        <td><span class="pf-pill <?= ($product['active'] ?? true) ? 'ok' : 'neutral' ?>"><?= ($product['active'] ?? true) ? '在售' : '下架' ?></span></td>
        <td style="white-space:nowrap">
          <a class="pf-btn ghost sm" href="<?= pf_url('/admin/products/' . (string) $product['id'] . '/edit') ?>">编辑</a>
          <a class="pf-btn ghost sm" href="<?= pf_url('/checkout?product=' . (string) $product['id']) ?>" target="_blank" rel="noopener">预览</a>
          <form method="post" action="<?= pf_url('/admin/products/' . (string) $product['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('确认删除？')">
            <button class="pf-btn ghost sm" type="submit">删除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($products === []): ?><tr><td colspan="7" class="pf-faint">还没有商品，点右上角新建。</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
