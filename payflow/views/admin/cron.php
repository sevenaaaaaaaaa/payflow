<?php
$active = 'subscriptions';
require __DIR__ . '/../partials/admin-head.php';
?>

<section style="padding:28px 0 16px">
  <h1 style="font-size:24px;font-weight:700">定时任务执行结果</h1>
  <p class="pf-muted" style="margin-top:6px">生产环境建议用系统 cron 调 <code class="pf-mono">php payflow/bin/cron.php</code>。</p>
</section>

<div class="pf-card">
  <h3 style="margin-bottom:10px">到期前提醒</h3>
  <pre class="pf-code"><?= pf_e(json_encode($report['subscription_reminders'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
  <h3 style="margin:18px 0 10px">续费处理</h3>
  <pre class="pf-code"><?= pf_e(json_encode($report['subscription_due'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
  <h3 style="margin:18px 0 10px">佣金解冻</h3>
  <pre class="pf-code"><?= pf_e(json_encode($report['commissions_matured'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
  <h3 style="margin:18px 0 10px">Webhook 重试</h3>
  <pre class="pf-code"><?= pf_e(json_encode($report['webhooks_retried'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
</div>

<p style="margin-top:16px"><a class="pf-btn ghost" href="<?= pf_url('/admin/subscriptions') ?>">返回订阅</a></p>

<?php require __DIR__ . '/../partials/admin-foot.php'; ?>
