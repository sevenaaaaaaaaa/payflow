<?php
/** @var string $title */
$embed = $embed ?? false;
$themeScript = "try{var t=localStorage.getItem('pf-theme');if(!t){t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'}document.documentElement.setAttribute('data-theme',t)}catch(e){}";
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= pf_e($title ?? 'PayFlow') ?></title>
<link rel="stylesheet" href="<?= pf_asset('tokens.css') ?>">
<link rel="stylesheet" href="<?= pf_asset('modules.css') ?>">
<link rel="stylesheet" href="<?= pf_asset('checkout.css') ?>">
<script><?= $themeScript ?></script>
</head>
<body>
<div class="pf-wrap<?= $embed ? ' pf-narrow' : '' ?>">
<?php if (!$embed): ?>
<div class="pf-nav">
  <span class="pf-brand"><span class="dot"></span>PayFlow</span>
</div>
<?php endif; ?>
