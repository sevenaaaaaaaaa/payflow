<?php
$active = 'webhooks';
require __DIR__ . '/../partials/admin-head.php';
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">Webhook 投递</h1>
  <p class="pf-muted" style="margin-top:6px">失败按 30s/1m/2m/4m… 退避重试，最多 5 次；可手动重发。</p>
</section>

<div class="pf-card" style="margin-bottom:18px">
  <h3 style="margin-bottom:10px">投递目标（data/config.json → webhooks.endpoints）</h3>
  <table class="pf-table">
    <thead><tr><th>名称</th><th>URL</th><th>事件</th></tr></thead>
    <tbody>
    <?php foreach (($targets ?? []) as $t): ?>
      <tr>
        <td><?= pf_e((string) $t['name']) ?></td>
        <td class="pf-mono" style="max-width:420px;word-break:break-all;font-size:11.5px"><?= pf_e((string) $t['url']) ?></td>
        <td class="pf-mono"><?= pf_e(implode(', ', (array) $t['events'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (($targets ?? []) === []): ?><tr><td colspan="3" class="pf-faint">未配置目标（webhooks.endpoints 或 webhooks.order）</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>时间</th><th>目标</th><th>事件</th><th>状态</th><th>次数</th><th>最近错误</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($deliveries as $d): ?>
      <tr>
        <td class="pf-faint"><?= pf_e(substr((string) $d['created_at'], 0, 16)) ?></td>
        <td><?= pf_e((string) ($d['endpoint'] ?? 'default')) ?></td>
        <td class="pf-mono"><?= pf_e((string) $d['event']) ?></td>
        <td><span class="pf-pill <?= ($d['status'] ?? '') === 'success' ? 'ok' : (($d['status'] ?? '') === 'failed' ? 'danger' : 'warn') ?>"><?= pf_e((string) $d['status']) ?></span></td>
        <td><?= (int) ($d['attempts'] ?? 0) ?></td>
        <td class="pf-faint" style="max-width:300px;word-break:break-all"><?= pf_e((string) ($d['last_error'] ?? '')) ?></td>
        <td>
          <form method="post" action="<?= pf_url('/admin/webhooks/' . (string) $d['id'] . '/resend') ?>" style="display:inline">
            <button class="pf-btn ghost sm" type="submit">重发</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($deliveries === []): ?><tr><td colspan="7" class="pf-faint">暂无投递记录</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
