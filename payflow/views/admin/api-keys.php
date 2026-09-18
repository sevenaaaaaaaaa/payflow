<?php
$active = 'api-keys';
require __DIR__ . '/../partials/admin-head.php';
?>

<section class="page-head">
  <div>
    <h1 style="font-size:24px;font-weight:700">API 密钥</h1>
    <p class="pf-muted" style="margin-top:6px">用于对外 API 与矩阵产品互通（HMAC 或 Bearer，见 <span class="pf-mono">docs/API.md</span>）。</p>
  </div>
  <form method="post" action="<?= pf_url('/admin/api-keys/new') ?>" class="pf-row-between" style="gap:8px">
    <input class="pf-input" name="name" placeholder="用途备注" style="width:160px">
    <select class="pf-select" name="mode" style="width:110px">
      <option value="live">正式</option>
      <option value="test">沙箱</option>
    </select>
    <button class="pf-btn" type="submit">新建密钥</button>
  </form>
</section>

<?php if (!empty($newKey)): ?>
  <div class="pf-notice ok" style="margin-bottom:18px">
    <strong>密钥已创建，仅显示一次，请立即保存。</strong>
    <div class="pf-code" style="margin-top:10px">key_id: <?= pf_e((string) $newKey['key_id']) ?>
secret: <?= pf_e((string) $newKey['secret']) ?></div>
  </div>
<?php endif; ?>

<div class="pf-card">
  <div class="table-wrap"><table class="pf-table">
    <thead><tr><th>名称</th><th>模式</th><th>Key ID</th><th>请求数</th><th>最近使用</th><th>状态</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($keys as $key): ?>
      <tr>
        <td><?= pf_e((string) $key['name']) ?></td>
        <td><span class="pf-pill <?= ($key['mode'] ?? 'live') === 'test' ? 'warn' : 'ok' ?>"><?= ($key['mode'] ?? 'live') === 'test' ? '沙箱' : '正式' ?></span></td>
        <td class="pf-mono"><?= pf_e((string) $key['key_id']) ?></td>
        <td><?= (int) ($key['requests'] ?? 0) ?></td>
        <td class="pf-faint"><?= pf_e((string) ($key['last_used_at'] ?? '—')) ?></td>
        <td><span class="pf-pill <?= ($key['active'] ?? true) ? 'ok' : 'neutral' ?>"><?= ($key['active'] ?? true) ? '启用' : '已吊销' ?></span></td>
        <td>
          <?php if ($key['active'] ?? true): ?>
            <form method="post" action="<?= pf_url('/admin/api-keys/' . (string) $key['id'] . '/revoke') ?>" style="display:inline" onsubmit="return confirm('吊销该密钥？')">
              <button class="pf-btn ghost sm" type="submit">吊销</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($keys === []): ?><tr><td colspan="7" class="pf-faint">还没有密钥</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
