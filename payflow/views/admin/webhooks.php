<?php
$active = 'webhooks';
require __DIR__ . '/../partials/admin-head.php';
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>

<section class="page-head">
  <div>
    <h1>Webhook 投递</h1>
    <p class="sub">共 <?= (int) $total ?> 条 · 失败按 30s/1m/2m/4m… 退避重试，最多 5 次；可手动重发。</p>
  </div>
</section>

<div class="pf-card" style="margin-bottom:18px">
  <h3 class="card-title">投递目标（data/config.json → webhooks.endpoints）</h3>
  <div class="table-wrap">
    <table class="pf-table">
      <thead><tr><th>名称</th><th>URL</th><th>事件</th></tr></thead>
      <tbody>
      <?php foreach (($targets ?? []) as $t): ?>
        <tr>
          <td><?= pf_e((string) $t['name']) ?></td>
          <td class="mono" style="max-width:420px;word-break:break-all;font-size:11.5px"><?= pf_e((string) $t['url']) ?></td>
          <td class="mono"><?= pf_e(implode(', ', (array) $t['events'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (($targets ?? []) === []): ?><tr><td colspan="3" class="empty">未配置目标（webhooks.endpoints 或 webhooks.order）</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="pf-card">
  <h3 class="card-title">投递记录</h3>
  <div class="table-wrap">
    <table class="pf-table">
      <thead><tr><th>时间</th><th>目标</th><th>事件</th><th>状态</th><th class="num">次数</th><th>最近错误</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($deliveries as $d): ?>
        <tr>
          <td class="pf-faint nowrap"><?= pf_e(substr((string) $d['created_at'], 0, 16)) ?></td>
          <td><?= pf_e((string) ($d['endpoint'] ?? 'default')) ?></td>
          <td class="mono"><?= pf_e((string) $d['event']) ?></td>
          <td><span class="pf-pill <?= ($d['status'] ?? '') === 'success' ? 'ok' : (($d['status'] ?? '') === 'failed' ? 'danger' : 'warn') ?>"><?= pf_e((string) $d['status']) ?></span></td>
          <td class="num"><?= (int) ($d['attempts'] ?? 0) ?></td>
          <td class="pf-faint" style="max-width:300px;word-break:break-all"><?= pf_e((string) ($d['last_error'] ?? '')) ?></td>
          <td class="nowrap">
            <form method="post" action="<?= pf_url('/admin/webhooks/' . (string) $d['id'] . '/resend') ?>" style="display:inline">
              <button class="pf-btn ghost sm" type="submit">重发</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($deliveries === []): ?><tr><td colspan="7" class="empty">暂无投递记录</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pf_pager($page, $pages, '/admin/webhooks') ?>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
