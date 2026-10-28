<div class="page-head"><div><h1>Security</h1><p class="muted">Two-factor authentication, recovery codes and active sessions.</p></div></div>
<?php require ROOT . '/views/partials/account_tabs.php'; ?>


<?php if ($codes): ?>
<div class="card warn-card">
  <h3>Recovery codes</h3>
  <p>Store these somewhere safe. Each code works <b>once</b> if you lose your authenticator. They will not be shown again.</p>
  <pre class="mono codes"><?= e(implode("\n", $codes)) ?></pre>
</div>
<?php endif; ?>

<div class="card">
  <div class="row between"><h3>Two-factor authentication</h3>
    <span class="badge" data-state="<?= $user['totp_enabled'] ? 'running' : 'offline' ?>"><?= $user['totp_enabled'] ? 'Enabled' : 'Disabled' ?></span></div>
  <?php if ($user['totp_enabled']): ?>
    <form method="post" action="/account/2fa/recovery" class="row gap wrap">
      <?= csrf_field() ?>
      <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password">
      <button class="btn ghost" type="submit">Regenerate recovery codes</button>
    </form>
    <?php if (!App\Auth::twoFaRequired($user)): ?>
    <form method="post" action="/account/2fa/disable" class="row gap wrap" data-confirm="Disable two-factor authentication?">
      <?= csrf_field() ?>
      <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password">
      <button class="btn danger" type="submit">Disable 2FA</button>
    </form>
    <?php endif; ?>
  <?php elseif ($setup): ?>
    <p>1. Scan this code with your authenticator app (Google Authenticator, Authy, 1Password, ...):</p>
    <div class="qr-wrap"><?= App\Qr::svg($setup['uri']) ?></div>
    <p class="small muted">Cannot scan? Enter this key manually:</p>
    <pre class="mono codes"><?= e(chunk_split($setup['secret'], 4, ' ')) ?></pre>
    <p>2. Enter the 6-digit code it shows:</p>
    <form method="post" action="/account/2fa/enable" class="row gap">
      <?= csrf_field() ?>
      <input name="code" class="mono code-input" inputmode="numeric" maxlength="7" required autocomplete="one-time-code">
      <button class="btn" type="submit">Activate</button>
    </form>
  <?php else: ?>
    <p class="muted">Protect your account with a time-based one-time password.</p>
    <form method="post" action="/account/2fa/start"><?= csrf_field() ?><button class="btn" type="submit">Set up 2FA</button></form>
  <?php endif; ?>
</div>

<div class="card">
  <div class="row between"><h3>Active sessions</h3>
    <form method="post" action="/account/sessions/revoke"><?= csrf_field() ?><button class="btn sm danger" type="submit">Sign out all others</button></form></div>
  <table class="table">
    <thead><tr><th>IP</th><th>Device</th><th>Started</th><th>Last active</th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $x): ?>
      <tr><td><?= ip_chip((string)$x['ip']) ?><?= (int)$x['id'] === (int)$user['session_id'] ? ' <span class="badge" data-state="running">this device</span>' : '' ?></td>
        <td class="small"><?= e(mb_substr($x['ua'], 0, 80)) ?></td>
        <td><?= time_tag((int)$x['created_at']) ?></td><td><?= time_tag((int)$x['last_seen']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
