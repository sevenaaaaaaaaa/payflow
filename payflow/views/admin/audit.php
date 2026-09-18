<?php
$active = 'audit';
require __DIR__ . '/../partials/admin-head.php';
$pages = max(1, (int) ceil(($total ?? count($events)) / max(1, $perPage ?? 50)));
?>

<section class="page-head">
  <div>
    <h1 style="font-size:24px;font-weight:700">操作审计</h1>
    <p class="pf-muted" style="margin-top:6px">共 <?= (int) ($total ?? count($events)) ?> 条 · 订单 / 订阅 / 佣金 / 提现 / 资产等全部事件。</p>
  </div>
  <form method="get" action="<?= pf_url('/admin/audit') ?>" class="pf-row-between" style="gap:8px">
    <input class="pf-input" name="q" value="<?= pf_e((string) $q) ?>" placeholder="筛选事件类型/关键字" style="width:220px">
    <button class="pf-btn ghost" type="submit">筛选</button>
  </form>
</section>

<div class="pf-card">
  <div class="table-wrap"><table class="pf-table">
    <thead><tr><th>时间</th><th>类型</th><th>详情</th></tr></thead>
    <tbody>
    <?php foreach ($events as $event): ?>
      <tr>
        <td class="pf-faint" style="white-space:nowrap"><?= pf_e(substr((string) $event['created_at'], 0, 19)) ?></td>
        <td class="pf-mono"><?= pf_e((string) $event['type']) ?></td>
        <td class="pf-mono" style="max-width:520px;word-break:break-all;font-size:11.5px"><?= pf_e((string) json_encode($event['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($events === []): ?><tr><td colspan="3" class="pf-faint">无匹配事件</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?= pf_pager($page, $pages, '/admin/audit', $q !== '' ? ['q' => $q] : []) ?>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
