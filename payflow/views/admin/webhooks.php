<?php
$active = 'webhooks';
require __DIR__ . '/../partials/admin-head.php';
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">Webhook 投递</h1>
  <p class="pf-muted" style="margin-top:6px">
    状态：<?= $configured ? '<span class="pf-pill ok">已配置</span>' : '<span class="pf-pill warn">未配置（data/config.json → webhooks.order）</span>' ?>
    · 失败按 30s/1m/2m/4m… 退避重试，最多 5 次。
  </p>
</section>

<div class="pf-card">
  <table class="pf-table">
    <thead><tr><th>时间</th><th>事件</th><th>状态</th><th>次数</th><th>最近错误</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($deliveries as $d): ?>
      <tr>
        <td class="pf-faint"><?= pf_e(substr((string) $d['created_at'], 0, 16)) ?></td>
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
    <?php if ($deliveries === []): ?><tr><td colspan="6" class="pf-faint">暂无投递记录</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
