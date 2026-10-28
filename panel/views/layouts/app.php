<?php
use App\Brand\Seal;
use App\Ui\Assets;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$nav = function (string $href, string $label, string $icon, bool $exact = false, string $not = '') use ($path): string {
    $on = ($exact ? $path === $href : str_starts_with($path, $href)) && ($not === '' || !str_starts_with($path, $not));
    return '<a class="nav-item' . ($on ? ' active' : '') . '" href="' . e($href) . '"><svg class="ico"><use href="#i-' . $icon . '"/></svg>' . e($label) . '</a>';
};
$brandName = App\Branding::name();
$brandLogo = App\Branding::logoUrl();
[$initials, $avClass] = Assets::avatar($user['username']);
$sessionCount = (int)App\DB::val('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$user['id']]);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= e(App\Security::csrf()) ?>">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? 'Panel') ?> · <?= e(App\Branding::name()) ?></title>
<link rel="icon" href="<?= e(App\Branding::logoUrl() ?? '/favicon.svg') ?>">
<link rel="stylesheet" href="<?= e(Assets::css()) ?>">
<link rel="stylesheet" href="/theme.css?v=<?= e(App\Theme::version()) ?>">
<script src="<?= e(Assets::js()) ?>" defer></script>
</head>
<body>
<?php require __DIR__ . '/../partials/sprite.php'; ?>
<aside class="sidebar" id="sidebar">
  <a class="brand" href="/"><?php if ($brandLogo): ?><img class="brand-logo" src="<?= e($brandLogo) ?>" alt=""><?php else: ?><svg class="logo"><use href="#logo-v"/></svg><?php endif; ?><span class="brand-name"><?= e($brandName) ?></span></a>
  <?php if (isset($s, $tab) && is_array($s) && isset($s['sub_perms'])):
    $sb = '/server/' . $s['uuid'];
    $can = static fn(string $k) => in_array($k, $s['sub_perms'], true);
    $mine = !empty($s['is_owner']);
    $isMc = ($s['preset_key'] ?? '') === 'minecraft_paper' || stripos((string)$s['egg_name'], 'minecraft') !== false;
    $sn = static fn(string $t, string $label, string $icon, string $href) => '<a class="nav-item' . ($tab === $t ? ' active' : '') . '" href="' . e($href) . '"><svg class="ico"><use href="#i-' . $icon . '"/></svg>' . e($label) . '</a>'; ?>
  <nav class="srv-nav">
    <a class="srv-back" href="/"><svg class="ico"><use href="#i-back"/></svg>All servers</a>
    <div class="srv-card"><b><?= e($s['name']) ?></b><span class="mono small muted"><?= e($s['addr']) ?></span></div>
    <?php if ($can('console') || $can('files')): ?><div class="nav-group">Server</div><?php endif; ?>
    <?= $can('console') ? $sn('console', 'Console', 'term', $sb) : '' ?>
    <?= $can('files') ? $sn('files', 'Files', 'folder', $sb . '/files') : '' ?>
    <?php if (($isMc && $can('plugins')) || $can('console')): ?><div class="nav-group">Extend</div><?php endif; ?>
    <?= $isMc && $can('plugins') ? $sn('plugins', 'Plugins', 'puzzle', $sb . '/plugins') : '' ?>
    <?= $can('console') ? $sn('activity', 'Activity', 'pulse', $sb . '/activity') : '' ?>
    <?php if ($mine || $can('settings')): ?><div class="nav-group">Manage</div><?php endif; ?>
    <?= $mine ? $sn('subusers', 'Users', 'users', $sb . '/subusers') : '' ?>
    <?= $can('settings') ? $sn('settings', 'Settings', 'sliders', $sb . '/settings') : '' ?>
  </nav>
  <?php else: ?>
  <nav>
    <div class="nav-group">Menu</div>
    <?= $nav('/', 'Servers', 'servers', true) ?>
    <?= $nav('/account/api', 'API keys', 'key') ?>
    <?= $nav('/account', 'Settings', 'cog', false, '/account/api') ?>
  </nav>
  <?php endif; ?>
  <div class="side-user">
      <details class="acct">
        <summary aria-label="Account menu">
          <span class="pfp<?= $user['is_admin'] ? ' is-admin' : '' ?>"><?= avatar_html($user['username']) ?><?php if ($user['is_admin']): ?><svg class="pfp-badge" aria-label="Administrator"><use href="#i-shield"/></svg><?php endif; ?></span>
          <span class="acct-meta"><b><?= e($user['username']) ?></b><small><?= $user['is_admin'] ? 'Administrator' : 'Member' ?></small></span>
          <svg class="ico caret"><use href="#i-chevron"/></svg>
        </summary>
        <div class="acct-menu">
          <div class="acct-head">
            <span class="pfp<?= $user['is_admin'] ? ' is-admin' : '' ?>"><?= avatar_html($user['username'], 'lg') ?><?php if ($user['is_admin']): ?><svg class="pfp-badge" aria-label="Administrator"><use href="#i-shield"/></svg><?php endif; ?></span>
            <div><b><?= e($user['username']) ?></b><small class="muted"><?= e($user['email']) ?></small></div>
          </div>
          <a href="/account">Settings</a>
          <a href="/account/security">Two-factor</a>
          <a href="/account/activity">Activity</a>
          <a href="/account/theme">Theme</a>
          <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Sign out</button></form>
        </div>
      </details>
  </div>
</aside>
<div class="scrim" id="scrim"></div>
<div class="content">
  <header class="topbar">
    <button class="burger" id="burger" type="button" aria-label="Toggle menu"><svg class="ico"><use href="#i-menu"/></svg></button>
    <div class="top-title"><span class="top-app"><?= e($brandName) ?></span><span class="top-sep">/</span><b><?= e($title ?? '') ?></b></div>
    <div class="top-right">
      <?php if (App\Roles::inAdmin($user)): ?><a class="top-btn" href="/admin"><svg class="ico"><use href="#i-cog"/></svg>Admin</a><?php endif; ?>
    </div>
  </header>
  <main class="main">
    <?= $content ?>
  </main>
  <?= Seal::footer() ?>
</div>
<?php if ($flash): $okf = $flash[0] === 'ok'; ?><div class="flash-toast <?= $okf ? 'ok' : 'err' ?>" role="status"><svg class="ico"><use href="#i-<?= $okf ? 'check' : 'alert' ?>"/></svg><div><b><?= $okf ? 'Success' : 'Error' ?></b><span><?= e($flash[1]) ?></span></div></div><?php endif; ?>
</body>
</html>
