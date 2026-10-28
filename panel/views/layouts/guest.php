<?php
use App\Brand\Seal;
use App\Ui\Assets;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(App\Security::csrf()) ?>">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? 'Sign in') ?> · <?= e(App\Branding::name()) ?></title>
<link rel="icon" href="<?= e(App\Branding::logoUrl() ?? '/favicon.svg') ?>">
<link rel="stylesheet" href="<?= e(Assets::css()) ?>">
<link rel="stylesheet" href="/theme.css?v=<?= e(App\Theme::version()) ?>">
</head>
<body class="guest">
<?php require __DIR__ . '/../partials/sprite.php'; ?>
<div class="auth-shell">
  <div class="auth-wrap">
    <div class="auth-brand"><?php $bl = App\Branding::logoUrl(); ?><?php if ($bl): ?><img class="brand-logo" src="<?= e($bl) ?>" alt=""><?php else: ?><svg class="logo"><use href="#logo-v"/></svg><?php endif; ?> <?= e(App\Branding::name()) ?></div>
    <div class="card auth-card">
      <?= $content ?>
    </div>
  </div>
  <?= Seal::footer() ?>
</div>
<?php if ($flash): $okf = $flash[0] === 'ok'; ?><div class="flash-toast <?= $okf ? 'ok' : 'err' ?>" role="status"><svg class="ico"><use href="#i-<?= $okf ? 'check' : 'alert' ?>"/></svg><div><b><?= $okf ? 'Success' : 'Error' ?></b><span><?= e($flash[1]) ?></span></div></div><?php endif; ?>
</body>
</html>
