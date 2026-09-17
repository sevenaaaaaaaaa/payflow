<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PayFlow 后台登录</title>
<link rel="stylesheet" href="<?= pf_asset('tokens.css') ?>">
<link rel="stylesheet" href="<?= pf_asset('modules.css') ?>">
<link rel="stylesheet" href="<?= pf_asset('checkout.css') ?>">
</head>
<body>
<div class="pf-wrap pf-narrow">
  <div class="pf-nav"><span class="pf-brand"><span class="dot"></span>PayFlow · 后台</span></div>
  <section class="pf-hero pf-center">
    <h1 style="font-size:26px">后台登录</h1>
    <p class="pf-muted" style="margin-top:8px">请输入用户名与密码。</p>
  </section>
  <form class="pf-card" method="post" action="<?= pf_url('/') ?>">
    <?php if (!$configured): ?>
      <div class="pf-notice danger" style="margin-bottom:14px">
        尚未配置后台密码。请在服务器 <code>data/config.json</code> 设置 <code>admin.password_hash</code>（推荐）或 <code>admin.password</code>。
      </div>
    <?php elseif (!empty($error)): ?>
      <div class="pf-notice danger" style="margin-bottom:14px"><?= pf_e((string) $error) ?></div>
    <?php endif; ?>
    <div class="pf-field">
      <label for="username">用户名</label>
      <input class="pf-input" type="text" id="username" name="username" value="admin" autocomplete="username" required autofocus>
    </div>
    <div class="pf-field">
      <label for="password">密码</label>
      <input class="pf-input" type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="pf-btn block" type="submit">登录</button>
  </form>
</div>
</body>
</html>
