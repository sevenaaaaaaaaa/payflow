<?php $code = $code ?? 500; ?>
<?php require __DIR__ . '/partials/head.php'; ?>

<section class="pf-hero pf-center">
  <span class="pf-eyebrow">Error <?= (int) $code ?></span>
  <h1 style="font-size:30px;margin-top:14px"><?= pf_e((string) $message) ?></h1>
  <p class="pf-muted" style="margin-top:10px">如需帮助，请联系站点管理员。</p>
</section>

<?php if (!empty($payload) && !empty($payload['trace'])): ?>
  <pre class="pf-code"><?= pf_e((string) $payload['type']) ?>: <?= pf_e((string) $payload['error']) ?>
<?= pf_e((string) $payload['file']) ?>

<?= pf_e(implode("\n", (array) $payload['trace'])) ?></pre>
<?php endif; ?>

<?php require __DIR__ . '/partials/foot.php'; ?>
